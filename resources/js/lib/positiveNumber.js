// A number of the administration above zero, or the fallback: a cleared field saves an empty value
export const positiveNumber = (value, fallback = null) => {
  const number = typeof value === 'number' ? value : value === '' || value == null ? NaN : Number(value)
  return Number.isFinite(number) && number > 0 ? number : fallback
}
