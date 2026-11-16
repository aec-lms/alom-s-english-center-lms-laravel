<form method="POST" action="{{ route('admin.recycle-bin.permanent-delete', ['type' => $type, 'recordId' => $recordId]) }}" onsubmit="return confirm('Permanently delete this record? Its linked data may prevent deletion. This action cannot be undone.')">
    @csrf
    <label class="flex items-center gap-2 text-[11px] text-rose-700"><input type="checkbox" name="confirm_permanent_delete" value="1" required> I understand this cannot be undone</label>
    <button class="px-4 py-2 rounded-lg border border-rose-300 text-rose-700 hover:bg-rose-50 text-xs font-bold">Permanently Delete</button>
</form>
