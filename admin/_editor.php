<?php
// ตัวแก้ไข WYSIWYG ที่ใช้ร่วมกัน: ตั้ง $editorSelector (เช่น '#lessonContent') ก่อน include
?>
<!-- ตัวแก้ไข WYSIWYG: TinyMCE 6 (MIT) — ถ้าโหลดไม่ได้จะใช้ช่องข้อความ HTML ตามเดิม -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.5/tinymce.min.js"></script>
<script>
    if (window.tinymce) {
        tinymce.init({
            selector: '<?= $editorSelector ?? '#lessonContent' ?>',
            base_url: 'https://cdn.jsdelivr.net/npm/tinymce@6.8.5',
            suffix: '.min',
            language: 'th_TH',
            language_url: 'https://cdn.jsdelivr.net/npm/tinymce-i18n@24.11.25/langs6/th_TH.js',
            height: 520,
            menubar: 'edit insert format table view',
            plugins: 'lists advlist link image table code autolink charmap searchreplace wordcount fullscreen preview',
            toolbar: 'undo redo | blocks | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright | bullist numlist outdent indent | link image table | charmap removeformat | code preview fullscreen',
            branding: false,
            promotion: false,
            relative_urls: false,
            remove_script_host: true,
            invalid_elements: 'script,iframe,object,embed,form,input,button,style',
            link_default_target: '_blank',
            image_dimensions: false,
            content_css: 'https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600&display=swap',
            content_style: "body{font-family:'Prompt',sans-serif;font-size:16px;line-height:1.6} img{max-width:100%;height:auto} table{border-collapse:collapse} td,th{border:1px solid #ccc;padding:4px 8px}",
            // อัปโหลดรูป (แทรก/ลากวาง/วาง) ไปเก็บใน uploads/editor
            images_upload_handler: function (blobInfo, progress) {
                var fd = new FormData();
                fd.append('file', blobInfo.blob(), blobInfo.filename());
                return fetch('<?= url('admin/editor_upload.php') ?>', {
                    method: 'POST', body: fd, credentials: 'same-origin',
                    headers: {'X-CSRF-Token': '<?= csrf_token() ?>'}
                }).then(function (r) {
                    return r.json().then(function (j) {
                        if (!r.ok || !j.location) throw {message: j.error || 'อัปโหลดรูปไม่สำเร็จ', remove: true};
                        return j.location;
                    });
                });
            }
        });
    }
</script>
