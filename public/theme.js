// The light or dark choice of this browser (account menu, resources/js/lib/theme.js), set before
// the page shows: no flash of the theme of the system. Nothing chosen: the system decides.
(function () {
  try {
    var theme = window.localStorage.getItem('ot-theme')
    if (theme === 'light' || theme === 'dark') document.documentElement.setAttribute('data-theme', theme)
  } catch (error) {
    // No storage: the system decides
  }
})()
