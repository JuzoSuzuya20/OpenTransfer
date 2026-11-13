// "Delete expired shares" runs in the background: how far it got, from the list the page last read

// The shares of the batch: the expired ones not deleted yet
export const sharesToDelete = (returned) => returned.filter((share) => share.status !== 'deleted').map((share) => share.id)

// A share of the batch is done once the list no longer has it, or has it as deleted
export const pruneProgress = (batch, shares) => {
  const byId = new Map(shares.map((share) => [share.id, share]))
  const done = batch.filter((id) => !byId.has(id) || byId.get(id).status === 'deleted').length
  return { done, total: batch.length, over: done === batch.length }
}
