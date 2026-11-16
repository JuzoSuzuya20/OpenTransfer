// A 401 that refuses the password of a share answers the request: no renewal of the session, no
// sign-out, and above all no second sending, which would count against the four tries
const PASSWORD_REFUSALS = ['invalid_password', 'password_required']

export const isPasswordRefusal = (body) => PASSWORD_REFUSALS.includes(body?.code)
