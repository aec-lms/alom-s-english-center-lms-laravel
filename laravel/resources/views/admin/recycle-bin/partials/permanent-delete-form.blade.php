<form method="POST" action="{{ route('admin.recycle-bin.permanent-delete', ['type' => $type, 'recordId' => $recordId]) }}" onsubmit="return confirm('Permanently delete this record? Its linked data may prevent deletion. This action cannot be undone.')">
    @csrf
    <input type="hidden" name="confirm_permanent_delete" value="1">
    <button class="px-4 py-2 rounded-lg border border-rose-300 text-rose-700 hover:bg-rose-50 text-xs font-bold">Permanently Delete</button>
</form>
