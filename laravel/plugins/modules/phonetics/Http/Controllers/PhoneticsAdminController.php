<?php
declare(strict_types=1);
namespace Plugins\Modules\Phonetics\Http\Controllers;

use Illuminate\Routing\Controller;
use Plugins\Modules\Phonetics\Models\PhoneticAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Plugins\Modules\Phonetics\Models\PhoneticSetting;
use Plugins\Modules\Phonetics\Models\PhoneticEntry;

final class PhoneticsAdminController extends Controller
{
    public function index(): View
    {
        return view('phonetics::admin.index', [
            'setting' => PhoneticSetting::current(),
            'entries' => PhoneticEntry::query()->orderBy('sort_order')->paginate(30),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'course_slug' => ['required', 'string', 'max:120'],
            'course_title' => ['required', 'string', 'max:160'],
            'show_articulatory_notes' => ['nullable', 'boolean'],
            'allow_slow_playback' => ['nullable', 'boolean'],
            'page_bg_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'default_card_bg_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'default_text_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme_profile_id' => ['required', 'in:classic-slate,warm-studio,night-lab,vibrant-classroom,custom'],
        ]);

        $palettes = [
            'classic-slate' => ['page_bg_color' => '#F8FAFC', 'default_card_bg_color' => '#FFFFFF', 'default_text_color' => '#0F172A', 'accent_color' => '#1D4ED8'],
            'warm-studio' => ['page_bg_color' => '#FFF7ED', 'default_card_bg_color' => '#FFFFFF', 'default_text_color' => '#431407', 'accent_color' => '#C2410C'],
            'night-lab' => ['page_bg_color' => '#0F172A', 'default_card_bg_color' => '#1E293B', 'default_text_color' => '#F8FAFC', 'accent_color' => '#38BDF8'],
            'vibrant-classroom' => ['page_bg_color' => '#F0FDF4', 'default_card_bg_color' => '#FFFFFF', 'default_text_color' => '#052E16', 'accent_color' => '#16A34A'],
        ];
        $palette = $data['theme_profile_id'] === 'custom'
            ? $data
            : $palettes[$data['theme_profile_id']];

        DB::transaction(function () use ($request, $data, $palette): void {
            $setting = PhoneticSetting::query()->firstOrNew(['id' => 1]);
            $setting->fill([
                'enabled' => $request->boolean('enabled'),
                'course_slug' => Str::slug($data['course_slug']),
                'course_title' => $data['course_title'],
                'require_course_enrollment' => true,
                'course_scoped_only' => true,
                'show_articulatory_notes' => $request->boolean('show_articulatory_notes'),
                'allow_slow_playback' => $request->boolean('allow_slow_playback'),
                'theme_profile_id' => $data['theme_profile_id'],
                'page_bg_color' => strtoupper($palette['page_bg_color']),
                'default_card_bg_color' => strtoupper($palette['default_card_bg_color']),
                'default_text_color' => strtoupper($palette['default_text_color']),
                'accent_color' => strtoupper($palette['accent_color']),
            ])->save();

            $this->recordAudit($request, 'phonetics.settings.updated', 'phonetics_settings', 'global', [
                'course_slug' => $setting->course_slug,
                'enabled' => $setting->enabled,
                'theme_profile_id' => $setting->theme_profile_id,
            ]);
        });

        Cache::forget('phonetics.settings');

        return back()->with('status', 'Phonetics settings saved.');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:80'],
            'category' => ['required', 'in:rhoticity,trap-bath,t-flapping,lot-vowel,yod-dropping,word-stress'],
            'uk_ipa' => ['required', 'string', 'max:100'],
            'us_ipa' => ['required', 'string', 'max:100'],
            'uk_note' => ['nullable', 'string', 'max:255'],
            'us_note' => ['nullable', 'string', 'max:255'],
            'example_sentence' => ['nullable', 'string', 'max:255'],
            'card_bg_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_bg_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'card_animation' => ['nullable', 'in:none,fade-up,float,pulse-glow,shimmer'],
            'uk_audio' => ['nullable', 'file', 'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/ogg,application/ogg,audio/mp4,audio/webm,video/webm', 'max:5120'],
            'us_audio' => ['nullable', 'file', 'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/ogg,application/ogg,audio/mp4,audio/webm,video/webm', 'max:5120'],
            'card_bg_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_uk_audio' => ['nullable', 'boolean'],
            'remove_us_audio' => ['nullable', 'boolean'],
            'remove_card_bg_image' => ['nullable', 'boolean'],
        ]);

        $newPaths = [];
        $data['sort_order'] = (PhoneticEntry::query()->max('sort_order') ?? 0) + 1;
        $data['is_active'] = true;
        $data['card_animation'] = $data['card_animation'] ?? 'none';

        try {
            foreach (['uk_audio' => 'audio/uk', 'us_audio' => 'audio/us', 'card_bg_image' => 'images/cards'] as $field => $directory) {
                if ($request->hasFile($field)) {
                    $path = $this->storeMedia($request->file($field), $directory);
                    $newPaths[] = $path;
                    $data[match ($field) {
                        'uk_audio' => 'uk_audio_path',
                        'us_audio' => 'us_audio_path',
                        default => 'card_bg_image_path',
                    }] = $path;
                }
            }

            unset($data['uk_audio'], $data['us_audio'], $data['card_bg_image']);

            $entry = DB::transaction(function () use ($request, $data): PhoneticEntry {
                $entry = PhoneticEntry::query()->create($data);
                $this->recordAudit($request, 'phonetics.entry.created', 'phonetic_entry', (string) $entry->id, [
                    'word' => $entry->word,
                    'category' => $entry->category,
                    'has_uk_audio' => filled($entry->uk_audio_path),
                    'has_us_audio' => filled($entry->us_audio_path),
                    'has_card_image' => filled($entry->card_bg_image_path),
                ]);

                return $entry;
            });
        } catch (\Throwable $exception) {
            foreach ($newPaths as $path) {
                $this->deleteMedia($path);
            }
            throw $exception;
        }

        return back()->with('status', 'Phonetic entry added.');
    }

    public function update(Request $request, PhoneticEntry $entry)
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:80'],
            'category' => ['required', 'in:rhoticity,trap-bath,t-flapping,lot-vowel,yod-dropping,word-stress'],
            'uk_ipa' => ['required', 'string', 'max:100'],
            'us_ipa' => ['required', 'string', 'max:100'],
            'uk_note' => ['nullable', 'string', 'max:255'],
            'us_note' => ['nullable', 'string', 'max:255'],
            'example_sentence' => ['nullable', 'string', 'max:255'],
            'card_bg_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_bg_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'card_animation' => ['nullable', 'in:none,fade-up,float,pulse-glow,shimmer'],
            'is_active' => ['nullable', 'boolean'],
            'uk_audio' => ['nullable', 'file', 'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/ogg,application/ogg,audio/mp4,audio/webm,video/webm', 'max:5120'],
            'us_audio' => ['nullable', 'file', 'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/ogg,application/ogg,audio/mp4,audio/webm,video/webm', 'max:5120'],
            'card_bg_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $newPaths = [];
        $oldPaths = [];
        $data['is_active'] = $request->boolean('is_active');
        $data['card_animation'] = $data['card_animation'] ?? $entry->card_animation ?? 'none';

        try {
            $mediaFields = [
                'uk_audio' => ['directory' => 'audio/uk', 'column' => 'uk_audio_path', 'remove' => 'remove_uk_audio'],
                'us_audio' => ['directory' => 'audio/us', 'column' => 'us_audio_path', 'remove' => 'remove_us_audio'],
                'card_bg_image' => ['directory' => 'images/cards', 'column' => 'card_bg_image_path', 'remove' => 'remove_card_bg_image'],
            ];
            foreach ($mediaFields as $field => $media) {
                $column = $media['column'];
                if ($request->hasFile($field)) {
                    $path = $this->storeMedia($request->file($field), $media['directory']);
                    $newPaths[] = $path;
                    if ($entry->{$column}) {
                        $oldPaths[] = $entry->{$column};
                    }
                    $data[$column] = $path;
                } elseif ($request->boolean($media['remove']) && $entry->{$column}) {
                    $oldPaths[] = $entry->{$column};
                    $data[$column] = null;
                }
            }

            unset($data['uk_audio'], $data['us_audio'], $data['card_bg_image'], $data['remove_uk_audio'], $data['remove_us_audio'], $data['remove_card_bg_image']);

            DB::transaction(function () use ($request, $entry, $data): void {
                $entry->update($data);
                $this->recordAudit($request, 'phonetics.entry.updated', 'phonetic_entry', (string) $entry->id, [
                    'word' => $entry->word,
                    'category' => $entry->category,
                    'is_active' => $entry->is_active,
                ]);
            });
        } catch (\Throwable $exception) {
            foreach ($newPaths as $path) {
                $this->deleteMedia($path);
            }
            throw $exception;
        }

        foreach ($oldPaths as $path) {
            $this->deleteMedia($path);
        }

        return back()->with('status', 'Phonetic entry updated.');
    }

    public function destroy(Request $request, PhoneticEntry $entry)
    {
        DB::transaction(function () use ($request, $entry): void {
            $entry->update(['is_active' => false]);
            $this->recordAudit($request, 'phonetics.entry.archived', 'phonetic_entry', (string) $entry->id, [
                'word' => $entry->word,
            ]);
        });

        return back()->with('status', 'Entry archived. Practice history and media have been preserved.');
    }

    private function storeMedia(UploadedFile $file, string $subDirectory): string
    {
        $mime = (string) $file->getMimeType();
        $extension = match ($mime) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/ogg', 'application/ogg' => 'ogg',
            'audio/mp4' => 'm4a',
            'audio/webm', 'video/webm' => 'webm',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => strtolower((string) $file->guessExtension()),
        };
        $allowed = str_starts_with($subDirectory, 'audio/')
            ? ['mp3', 'wav', 'ogg', 'm4a', 'webm']
            : ['jpg', 'jpeg', 'png', 'webp'];
        if ($extension === 'mpga') {
            $extension = 'mp3';
        }
        abort_unless(in_array($extension, $allowed, true), 422, 'Unsupported phonetics media format.');

        $relativeDirectory = 'uploads/alom-english-center/phonetics/' . trim($subDirectory, '/');
        $absoluteDirectory = public_path($relativeDirectory);
        File::ensureDirectoryExists($absoluteDirectory);
        $filename = Str::random(32) . '.' . $extension;
        $file->move($absoluteDirectory, $filename);

        return $relativeDirectory . '/' . $filename;
    }

    private function deleteMedia(string $path): void
    {
        if (!preg_match('~^uploads/alom-english-center/phonetics/(?:audio/(?:uk|us)|images/cards)/[A-Za-z0-9._-]+$~', $path)) {
            return;
        }
        $absolutePath = public_path($path);
        if (File::exists($absolutePath)) {
            File::delete($absolutePath);
        }
    }

    private function recordAudit(Request $request, string $action, string $entityType, string $entityId, array $metadata = []): void
    {
        PhoneticAuditEvent::query()->create([
            'user_id' => $request->user()?->getAuthIdentifier(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $request->ip(),
            'metadata' => $metadata,
        ]);
    }
}
