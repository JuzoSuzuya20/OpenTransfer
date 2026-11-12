// A row that leaves a list is taken out of the flow (so the others can close up on a spring), but
// pinned where it was first: in a flex column it would otherwise jump to the top of the list.
export const pinLeaving = (element) => {
  const { offsetTop, offsetLeft, offsetWidth, offsetHeight } = element
  Object.assign(element.style, {
    top: `${offsetTop}px`,
    left: `${offsetLeft}px`,
    width: `${offsetWidth}px`,
    height: `${offsetHeight}px`
  })
}
