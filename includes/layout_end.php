  </div><!-- /.main-content -->
  <script>
  (function () {
      // Mobile: Sidebar ein- und ausblenden
      var btn     = document.getElementById('mobile-toggle-btn');
      var sidebar = document.getElementById('sidebar');
      var overlay = document.getElementById('sidebar-overlay');
      if (btn && sidebar && overlay) {
          btn.addEventListener('click', function () {
              sidebar.classList.toggle('active');
              overlay.classList.toggle('active');
          });
          overlay.addEventListener('click', function () {
              sidebar.classList.remove('active');
              overlay.classList.remove('active');
          });
      }

      // Desktop: Sidebar einklappen
      var collapseBtn = document.getElementById('sidebarCollapseBtn');
      if (collapseBtn) {
          collapseBtn.addEventListener('click', function () {
              var collapsed = document.body.classList.toggle('sidebar-collapsed');
              window.ansichtSpeicher.setItem('sidebarCollapsed', collapsed ? '1' : '0');
          });
      }
  })();
  </script>
  <script src="assets/js/sticky-header.js" defer></script>
<?php if (demo_mode()): ?>
  <script src="assets/js/demo.js" defer></script>
<?php endif; ?>
</body>
</html>
