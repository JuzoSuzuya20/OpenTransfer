// Kept out of the page so the Content-Security-Policy can forbid inline scripts
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-reload]').forEach((button) => {
    button.addEventListener('click', () => window.location.reload())
  })
})
