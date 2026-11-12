// The message under the brand on the sign-in screen. The one OpenTransfer is installed with
// (stored in English by the seeder) or none shows in the reader's language; anything an admin
// wrote is shown as written.
export const SHIPPED_LOGIN_MESSAGE = 'Login to your account to get started.'

export const loginMessageFor = (stored, translated) => {
  const text = String(stored ?? '').trim()
  return !text || text === SHIPPED_LOGIN_MESSAGE ? translated : text
}
