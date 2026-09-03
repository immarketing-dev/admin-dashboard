  </div><!-- /.main-content -->

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
              localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
          });
      }
  })();
  </script>
</body>
</html>
