add_action('wp_enqueue_scripts', function () {
    if (!isset($_GET['welcome']) || $_GET['welcome'] !== '1') return;

    wp_enqueue_style('sweetalert2','https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css',[],null);
    wp_enqueue_script('sweetalert2','https://cdn.jsdelivr.net/npm/sweetalert2@11',[],null,true);

    $inline = <<<JS
    (function() {
      function onReady(fn){document.readyState!=='loading'?fn():document.addEventListener('DOMContentLoaded',fn);}
      onReady(function(){
        if (typeof Swal === 'undefined') return;
        Swal.fire({
          title: 'Welcome to SoulMirror ✨',
          html: '<p style="margin:0.5rem 0 0;line-height:1.6;">Your account was created successfully.</p>'
              + '<p style="margin:0.25rem 0 0;">Explore our bundles to unlock your personalized readings.</p>',
          icon: 'success',
          confirmButtonText: 'Browse Offers',
          confirmButtonColor: '#38a169',
          backdrop: true,
          allowOutsideClick: true,
          allowEscapeKey: true,
        }).then(function(){
          // Remove ?welcome=1 from URL without reloading
          var u = new URL(window.location.href);
          u.searchParams.delete('welcome');
          window.history.replaceState({}, '', u.toString());
        });
      });
    })();
    JS;
    wp_add_inline_script('sweetalert2', $inline, 'after');
});
