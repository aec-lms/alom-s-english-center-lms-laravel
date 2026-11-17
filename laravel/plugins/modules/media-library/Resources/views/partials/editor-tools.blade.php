@once
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css">
<style>
    .aec-crop-backdrop{position:fixed;inset:0;background:#0f172acc;z-index:10000;display:none;align-items:center;justify-content:center;padding:1rem}
    .aec-crop-panel{background:#fff;border-radius:1rem;padding:1rem;width:min(760px,100%);max-height:90vh;overflow:auto}
    .aec-crop-panel img{display:block;max-width:100%;max-height:55vh}
    .aec-quill-wrap{background:#fff;color:#0f172a;font-size:14px}
    .aec-quill-wrap .ql-editor{min-height:180px}
</style>
<div id="aec-crop-modal" class="aec-crop-backdrop" role="dialog" aria-modal="true" aria-label="Crop uploaded image">
    <div class="aec-crop-panel space-y-3">
        <div class="font-bold text-slate-900">Crop &amp; optimize image</div>
        <div class="bg-slate-100 rounded-lg p-2"><img id="aec-crop-image" alt="Image crop preview"></div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap gap-1">
                <button type="button" data-crop-action="zoom-in" class="border rounded px-3 py-2 text-sm">Zoom +</button>
                <button type="button" data-crop-action="zoom-out" class="border rounded px-3 py-2 text-sm">Zoom −</button>
                <button type="button" data-crop-action="rotate-left" class="border rounded px-3 py-2 text-sm">↶ Rotate</button>
                <button type="button" data-crop-action="rotate-right" class="border rounded px-3 py-2 text-sm">↷ Rotate</button>
                <select id="aec-crop-ratio" class="border rounded px-2 py-2 text-sm"><option value="free">Free ratio</option><option value="1">Square 1:1</option><option value="1.7777778">Landscape 16:9</option><option value="0.75">Portrait 3:4</option></select>
            </div>
            <div class="flex gap-2">
                <button type="button" id="aec-crop-cancel" class="border rounded-lg px-4 py-2 text-sm">Keep original</button>
                <button type="button" id="aec-crop-save" class="bg-sky-700 text-white rounded-lg px-4 py-2 text-sm font-bold">Apply crop</button>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    let activeInput = null, cropper = null;
    const modal = document.getElementById('aec-crop-modal');
    const image = document.getElementById('aec-crop-image');
    const closeCrop = () => { modal.style.display = 'none'; if (cropper) cropper.destroy(); cropper = null; activeInput = null; };
    document.querySelectorAll('input[type="file"][accept*="image"]').forEach(input => {
        input.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file || !window.Cropper || !file.type.startsWith('image/')) return;
            activeInput = this;
            image.src = URL.createObjectURL(file);
            modal.style.display = 'flex';
            image.onload = () => { if (cropper) cropper.destroy(); cropper = new Cropper(image, { viewMode: 1, autoCropArea: .95, responsive: true, background: false }); };
        });
    });
    document.getElementById('aec-crop-cancel').addEventListener('click', closeCrop);
    document.querySelectorAll('[data-crop-action]').forEach(button => button.addEventListener('click', () => {
        if (!cropper) return;
        const action = button.dataset.cropAction;
        if (action === 'zoom-in') cropper.zoom(.1);
        if (action === 'zoom-out') cropper.zoom(-.1);
        if (action === 'rotate-left') cropper.rotate(-90);
        if (action === 'rotate-right') cropper.rotate(90);
    }));
    document.getElementById('aec-crop-ratio').addEventListener('change', function () {
        if (cropper) cropper.setAspectRatio(this.value === 'free' ? NaN : Number(this.value));
    });
    document.getElementById('aec-crop-save').addEventListener('click', function () {
        if (!cropper || !activeInput) return;
        const original = activeInput.files[0];
        const canvas = cropper.getCroppedCanvas({ maxWidth: 2400, maxHeight: 2400, imageSmoothingQuality: 'high' });
        canvas.toBlob(blob => {
            if (!blob) return;
            const ext = original.type === 'image/png' ? 'png' : 'jpg';
            const file = new File([blob], original.name.replace(/\.[^.]+$/, '') + '-cropped.' + ext, { type: ext === 'png' ? 'image/png' : 'image/jpeg' });
            const transfer = new DataTransfer(); transfer.items.add(file); activeInput.files = transfer.files;
            closeCrop();
        }, original.type === 'image/png' ? 'image/png' : 'image/jpeg', .9);
    });
    document.querySelectorAll('textarea[name="content"], textarea[name="description"]').forEach(textarea => {
        if (!window.Quill || textarea.dataset.richEditor === 'ready') return;
        textarea.dataset.richEditor = 'ready';
        const editor = document.createElement('div'); editor.className = 'aec-quill-wrap border rounded-lg'; textarea.insertAdjacentElement('afterend', editor); textarea.hidden = true;
        const quill = new Quill(editor, { theme: 'snow', modules: { toolbar: [['bold','italic','underline'], [{header:[1,2,3,false]}], [{list:'ordered'},{list:'bullet'}], ['blockquote','link'], ['clean']] } });
        if (textarea.value) quill.root.innerHTML = textarea.value;
        const form = textarea.closest('form');
        if (form) form.addEventListener('submit', () => { textarea.value = quill.root.innerHTML; });
    });
});
</script>
@endonce