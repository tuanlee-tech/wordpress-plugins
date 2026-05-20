(function () {
  function applyOverrides() {
    var config = window.scmThemeElementOverrides || {};
    var items = Array.isArray(config.items) ? config.items : [];

    items.forEach(function (item) {
      if (!item || !item.selector || typeof item.value === 'undefined') {
        return;
      }

      var nodes;

      try {
        nodes = document.querySelectorAll(item.selector);
      } catch (e) {
        return;
      }

      if (!nodes || !nodes.length) {
        return;
      }

      nodes.forEach(function (node) {
        if (item.mode === 'html') {
          node.innerHTML = item.value;
        } else {
          node.textContent = item.value;
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyOverrides);
  } else {
    applyOverrides();
  }
})();
