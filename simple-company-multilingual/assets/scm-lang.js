(function () {
  'use strict';

  const switchers = Array.from(document.querySelectorAll('[data-scm-language-switcher="dropdown"]'));

  if (!switchers.length) {
    return;
  }

  function closeSwitcher(switcher) {
    const trigger = switcher.querySelector('.scm-language-switcher__trigger');
    const dropdown = switcher.querySelector('.scm-language-switcher__dropdown');

    if (!trigger || !dropdown) {
      return;
    }

    switcher.classList.remove('is-open');
    dropdown.classList.remove('is-open');
    trigger.setAttribute('aria-expanded', 'false');
  }

  function closeAll(except) {
    switchers.forEach(function (switcher) {
      if (switcher !== except) {
        closeSwitcher(switcher);
      }
    });
  }

  function positionDropdown(switcher) {
    const trigger = switcher.querySelector('.scm-language-switcher__trigger');
    const dropdown = switcher.querySelector('.scm-language-switcher__dropdown');

    if (!trigger || !dropdown) {
      return;
    }

    const rect = trigger.getBoundingClientRect();
    dropdown.style.setProperty('--scm-trigger-width', rect.width + 'px');

    dropdown.classList.add('is-open');
    dropdown.style.left = '-9999px';
    dropdown.style.top = '-9999px';

    const menuWidth = dropdown.offsetWidth;
    const menuHeight = dropdown.offsetHeight;

    let left = rect.left;
    let top = rect.bottom + 8;

    if (left + menuWidth > window.innerWidth - 12) {
      left = rect.right - menuWidth;
    }

    if (left < 12) {
      left = 12;
    }

    if (top + menuHeight > window.innerHeight - 12) {
      top = rect.top - menuHeight - 8;
    }

    if (top < 12) {
      top = 12;
    }

    dropdown.style.left = left + 'px';
    dropdown.style.top = top + 'px';
  }

  function openSwitcher(switcher) {
    const trigger = switcher.querySelector('.scm-language-switcher__trigger');

    if (!trigger) {
      return;
    }

    closeAll(switcher);
    positionDropdown(switcher);
    switcher.classList.add('is-open');
    trigger.setAttribute('aria-expanded', 'true');
  }

  switchers.forEach(function (switcher) {
    const trigger = switcher.querySelector('.scm-language-switcher__trigger');

    if (!trigger) {
      return;
    }

    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();

      if (switcher.classList.contains('is-open')) {
        closeSwitcher(switcher);
        return;
      }

      openSwitcher(switcher);
    });
  });

  document.addEventListener('click', function (event) {
    if (!event.target.closest('[data-scm-language-switcher="dropdown"]')) {
      closeAll();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeAll();
    }
  });

  window.addEventListener('resize', function () {
    switchers.forEach(function (switcher) {
      if (switcher.classList.contains('is-open')) {
        positionDropdown(switcher);
      }
    });
  });

  window.addEventListener('scroll', function () {
    switchers.forEach(function (switcher) {
      if (switcher.classList.contains('is-open')) {
        positionDropdown(switcher);
      }
    });
  }, true);
})();
