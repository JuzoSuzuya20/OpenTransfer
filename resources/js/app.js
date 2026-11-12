import { createApp } from 'vue'
import { Tolgee, DevTools, VueTolgee } from '@tolgee/vue'
import { FormatIcu } from '@tolgee/format-icu'

// Served by the app itself: no visitor request goes to Google Fonts or a CDN
// Bootstrap's base only (element defaults); the components were never used
import 'bootstrap/dist/css/bootstrap-reboot.min.css'
import '@fontsource-variable/plus-jakarta-sans'
import './style.scss'
import App from './App.vue'
import Toast, { POSITION } from 'vue-toastification'
import 'vue-toastification/dist/index.css'
import { domData } from './domData'

// The languages, each in a file of its own fetched when it is needed: the reader's, and English
// for a missing key (fallbackLanguage), rather than all seven on every page
const language = (load) => () => load().then((module) => module.default)
let clickOutsideEvent = null

const showHelpTip = (event, helpTipId) => {
  const helpTip = document.querySelector(helpTipId)
  const cssShiftAmount = -15
  if (!helpTip) return

  helpTip.classList.add('no-transition')
  
  // Calculate initial position
  let top = event.clientY - helpTip.offsetHeight - 10
  let left = event.clientX - helpTip.offsetWidth / 2

  // Check top boundary - account for the shift amount
  if (top + cssShiftAmount < 0) {
    top = -cssShiftAmount // This ensures the tip is at the very top after the shift is applied
  }
  
  // Check left boundary
  if (left < 0) {
    left = 0
  }
  
  // Check right boundary
  if (left + helpTip.offsetWidth > window.innerWidth) {
    left = window.innerWidth - helpTip.offsetWidth
  }
  
  // Check bottom boundary - account for the shift amount
  if (top + cssShiftAmount + helpTip.offsetHeight > window.innerHeight) {
    top = window.innerHeight - helpTip.offsetHeight - cssShiftAmount
  }

  // Apply the position
  helpTip.style.top = `${top}px`
  helpTip.style.left = `${left}px`

  // Make the tooltip visible with a slight delay
  setTimeout(() => {
    helpTip.classList.remove('no-transition')
    helpTip.classList.add('visible')
    helpTip.classList.add('shifted-up')
  }, 1)

  // Register click-outside event to hide the tooltip
  clickOutsideEvent = document.addEventListener('click', (event) => {
    if (!helpTip.contains(event.target)) {
      hideHelpTip(helpTipId)
    }
  })
}

const hideHelpTip = (helpTipId) => {
  const helpTip = document.querySelector(helpTipId)
  if (!helpTip) return

  helpTip.classList.remove('visible')
  helpTip.classList.remove('shifted-up')

  //unregister click-outside event
  document.removeEventListener('click', clickOutsideEvent)
}

//use a mutation observer to recursively watch the dom for any .slide-text elements that might appear
const observer = new MutationObserver((mutations) => {
  const processNode = (node) => {
    if (node.classList && node.classList.contains('slide-text')) {
      const innerContent = node.querySelector('.content')
      if (innerContent) {
        const outerWidth = node.offsetWidth
        const innerWidth = innerContent.offsetWidth
        if (innerWidth > outerWidth) {
          innerContent.classList.add('needs-slide')
          node.classList.add('has-slide')
          innerContent.style.setProperty('--content-width', `${innerWidth}px`)
          const animationTime = Math.max(innerWidth / 100, 20) // Ensure a minimum animation time of 20 seconds
          innerContent.style.setProperty('--animation-time', `${animationTime}s`)
        }
      }
    }
    node.childNodes.forEach((child) => processNode(child))
  }

  mutations.forEach((mutation) => {
    mutation.addedNodes.forEach((node) => {
      processNode(node)
    })
  })
})

observer.observe(document.body, { childList: true, subtree: true })

const tolgee = Tolgee().use(DevTools()).use(FormatIcu()).init({
  language: localStorage.getItem('language') || domData().default_language || 'en',
  // a key missing from a translation shows the English text, never the raw key
  fallbackLanguage: 'en',

  // for development
  apiUrl:  import.meta.env.VITE_APP_TOLGEE_API_URL,
  apiKey:  import.meta.env.VITE_APP_TOLGEE_API_KEY,

  // for production
  staticData: {
    en: language(() => import('./i18n/en.json')),
    de: language(() => import('./i18n/de.json')),
    es: language(() => import('./i18n/es.json')),
    fr: language(() => import('./i18n/fr.json')),
    it: language(() => import('./i18n/it.json')),
    nl: language(() => import('./i18n/nl.json')),
    pt: language(() => import('./i18n/pt.json'))
  }
})

// The page says which language it is in (screen readers pick their voice from it), and sizes
// read in that language (lib/fileSize.js)
document.documentElement.lang = tolgee.getLanguage() || 'en'
tolgee.on('language', ({ value }) => {
  document.documentElement.lang = value
})


createApp(App)
  .use(Toast, {
    position: POSITION.BOTTOM_RIGHT,
    // Come in from the edge decelerating, leave towards it accelerating (styles/_motion.scss)
    transition: "ot-toast",
    // The countdown bar stays (its animationend closes the toast) but transparent: no linear motion
    hideProgressBar: true,
  })
  .use(VueTolgee, { tolgee })
  .provide('showHelpTip', showHelpTip)
  .provide('hideHelpTip', hideHelpTip)
  .mount('#app')
