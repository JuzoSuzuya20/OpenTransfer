// The two pages a recipient may meet on a download before the archive is ready
// (resources/views/shares): the typeface and the styles of the application, without Vue.
import '@fontsource-variable/plus-jakarta-sans'
import './style.scss'

document.addEventListener('DOMContentLoaded', () => {
  for (const button of document.querySelectorAll('[data-reload]')) {
    button.addEventListener('click', () => window.location.reload())
  }
})
