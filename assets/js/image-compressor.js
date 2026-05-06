/**
 * Auto-compresses image files before form upload so phone-camera photos
 * (often 5-15 MB) always fit under PHP upload limits without user action.
 *
 * Usage:
 *   <input type="file" name="employee_photo" accept="image/*"
 *          data-auto-compress="1" data-max-dim="1200" data-quality="0.85">
 *
 * The compressor runs on the input's `change` event, replaces the FileList
 * with a DataTransfer containing the compressed JPEG, and shows a small
 * status hint next to the input.
 */
(function () {
    'use strict';

    const DEFAULT_MAX_DIM = 1200;
    const DEFAULT_QUALITY = 0.85;
    const SKIP_BELOW_BYTES = 800 * 1024; // skip if already under 800 KB

    function readFileAsImage(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = e => {
                const img = new Image();
                img.onload = () => resolve(img);
                img.onerror = () => reject(new Error('No se pudo decodificar la imagen.'));
                img.src = e.target.result;
            };
            reader.onerror = () => reject(new Error('No se pudo leer el archivo.'));
            reader.readAsDataURL(file);
        });
    }

    function canvasToBlob(canvas, type, quality) {
        return new Promise((resolve, reject) => {
            canvas.toBlob(
                blob => (blob ? resolve(blob) : reject(new Error('Falló la compresión.'))),
                type,
                quality
            );
        });
    }

    async function compressFile(file, maxDim, quality) {
        const img = await readFileAsImage(file);
        let { width, height } = img;
        const longest = Math.max(width, height);
        if (longest > maxDim) {
            const scale = maxDim / longest;
            width = Math.round(width * scale);
            height = Math.round(height * scale);
        }
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, width, height);
        const blob = await canvasToBlob(canvas, 'image/jpeg', quality);
        const baseName = (file.name || 'photo').replace(/\.[^.]+$/, '');
        return new File([blob], baseName + '.jpg', {
            type: 'image/jpeg',
            lastModified: Date.now(),
        });
    }

    function showHint(input, text, color) {
        let hint = input.parentElement.querySelector('.image-compress-hint');
        if (!hint) {
            hint = document.createElement('p');
            hint.className = 'image-compress-hint text-xs mt-1';
            input.parentElement.appendChild(hint);
        }
        hint.textContent = text;
        hint.style.color = color;
    }

    function fmtKb(bytes) {
        return (bytes / 1024).toFixed(0) + ' KB';
    }

    async function handleChange(event) {
        const input = event.target;
        const file = input.files && input.files[0];
        if (!file || !file.type.startsWith('image/')) return;

        const maxDim = parseInt(input.dataset.maxDim, 10) || DEFAULT_MAX_DIM;
        const quality = parseFloat(input.dataset.quality) || DEFAULT_QUALITY;

        if (file.size <= SKIP_BELOW_BYTES) {
            showHint(input, `Imagen lista (${fmtKb(file.size)}).`, '#94a3b8');
            return;
        }

        input.dataset.compressorPending = '1';
        attachFormGuard(input);
        showHint(input, `Comprimiendo imagen (${fmtKb(file.size)})…`, '#60a5fa');
        try {
            const compressed = await compressFile(file, maxDim, quality);
            const dt = new DataTransfer();
            dt.items.add(compressed);
            input.files = dt.files;
            showHint(
                input,
                `Imagen comprimida: ${fmtKb(file.size)} → ${fmtKb(compressed.size)}.`,
                '#34d399'
            );
        } catch (err) {
            // Leave the original file in place; server-side will surface any error.
            showHint(input, 'No se pudo comprimir; se subirá la imagen original.', '#fbbf24');
            console.warn('Image compression failed:', err);
        } finally {
            delete input.dataset.compressorPending;
            const form = input.form;
            if (form && form.dataset.compressorAwaitingResubmit === '1') {
                delete form.dataset.compressorAwaitingResubmit;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        }
    }

    function attachFormGuard(input) {
        const form = input.form;
        if (!form || form.dataset.compressorGuardAttached === '1') return;
        form.dataset.compressorGuardAttached = '1';
        form.addEventListener(
            'submit',
            function (event) {
                const stillPending = form.querySelector(
                    'input[type="file"][data-compressor-pending="1"]'
                );
                if (stillPending) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    form.dataset.compressorAwaitingResubmit = '1';
                }
            },
            true // capture phase so we run before other submit handlers
        );
    }

    function init() {
        document
            .querySelectorAll('input[type="file"][data-auto-compress="1"]')
            .forEach(input => {
                if (input.dataset.compressorAttached === '1') return;
                input.dataset.compressorAttached = '1';
                input.addEventListener('change', handleChange);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    // Re-scan on dynamic content changes (e.g., modal opening).
    window.attachImageCompressors = init;
})();
