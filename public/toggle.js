// Shared mean/median switch. One cookie drives every duration/size cell; the
// backend always sends both series, this module only picks which is drawn.
export const MODE_MEAN = 'mean';
export const MODE_MEDIAN = 'median';

const COOKIE_NAME = 'cr-mm';

export function modeFromCookie(cookieString) {
  const match = new RegExp('(?:^|;\\s*)' + COOKIE_NAME + '=(mean|median)').exec(cookieString);

  return match ? match[1] : MODE_MEAN;
}

export function getMode() {
  return modeFromCookie(document.cookie);
}

export function cookieForMode(mode) {
  return COOKIE_NAME + '=' + mode + '; path=/; max-age=31536000; samesite=lax';
}

export function setMode(mode) {
  document.cookie = cookieForMode(mode);
}

export function toggleMode() {
  const next = getMode() === MODE_MEAN ? MODE_MEDIAN : MODE_MEAN;
  setMode(next);

  return next;
}
