<?php
declare(strict_types=1);
namespace Plugins\Modules\Phonetics\Models;
use Illuminate\Database\Eloquent\Model;
final class PhoneticEntry extends Model {
 protected $table='alom_english_center_phonetic_entries';protected $guarded=['id'];
 protected function casts():array{return['is_active'=>'boolean','sort_order'=>'integer'];}
 public function getUkAudioUrlAttribute():?string{return $this->mediaUrl($this->uk_audio_path);}
 public function getUsAudioUrlAttribute():?string{return $this->mediaUrl($this->us_audio_path);}
 public function getCardBgImageUrlAttribute():?string{return $this->mediaUrl($this->card_bg_image_path);}
 private function mediaUrl(?string $path):?string{if(!$path||!preg_match('~^uploads/alom-english-center/phonetics/(?:audio/(?:uk|us)|images/cards)/[A-Za-z0-9._-]+$~',$path))return null;return asset($path);}
}