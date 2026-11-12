// The sentences the server leaves after a round trip to an identity provider (session flash,
// data-error / data-success), each with the key of its translation
const KEYS = {
  'Account not found. Please check that you have linked your account to this provider.': 'flash.account_not_found',
  'An account with this email already exists. Please link your account to this provider from your profile settings.': 'flash.account_exists',
  'Authentication failed. Please try again.': 'flash.authentication_failed',
  'Authentication with the identity provider failed. Please try again.': 'flash.provider_failed',
  'Provider not found': 'flash.provider_not_found',
  'This account is disabled': 'flash.account_disabled',
  'User not found': 'flash.user_not_found',
  'Your identity provider has not verified your email address. Ask your administrator to verify it there, or link this account from your profile.': 'flash.email_not_verified',
  'Account linked successfully': 'flash.account_linked'
}

export const flashMessageKey = (message) => KEYS[message] ?? null
