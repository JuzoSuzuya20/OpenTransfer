// Where the window goes after a move within the application: to the anchor of the address, to the
// top of a new page, or nowhere when the link keeps it (the tabs of the administration)
export const scrollAfterNavigation = ({ hash, changed, keepScroll = false }) => {
  if (keepScroll) return { to: 'none' }
  if (hash) return { to: 'anchor', id: decodeURIComponent(hash.slice(1)) }
  return changed ? { to: 'top' } : { to: 'none' }
}
