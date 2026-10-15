/**
 * Browser twin of App\Support\Targets (PHP). Both are verified against
 * tests/fixtures/targets.json (PHP: tests/Unit/TargetsTest.php, JS: tools/qa/targets-parity.mjs).
 * The SERVER re-validates on checkout; this file only gives instant feedback.
 *
 * `linkOnly` (Instagram Followers / YouTube Subscribers) means the customer must paste the ACCOUNT LINK: a bare @username / @handle
 * is refused. Every other service keeps its "username or link" / post / video input.
 *
 * URLs are parsed by hand (not with `new URL`) so behaviour matches PHP exactly —
 * e.g. dot-segments are NOT resolved and credentials/odd ports are rejected.
 */
const IG_HOSTS = ['instagram.com', 'www.instagram.com', 'm.instagram.com', 'instagr.am', 'www.instagr.am'];
const YT_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'www.youtu.be'];
const IG_RESERVED = ['p', 'reel', 'reels', 'tv', 'explore', 'stories', 'accounts', 'direct', 'about', 'legal', 'developer', 'directory', 'web', 'challenge', 'oauth', 'privacy', 'press', 'api', 'static', 'emails', 'nametag', 'topics', 'login', 'signup', 'download'];
const YT_RESERVED = ['watch', 'shorts', 'playlist', 'results', 'feed', 'live', 'embed', 'v', 'channel', 'c', 'user', 'account', 'premium', 'gaming', 'music', 'kids', 'trending', 'hashtag', 'redirect', 'oembed', 'attribution_link', 'about', 't', 'howyoutubeworks', 'reporthistory'];

const BAD = { bad: true };
const ok = (url, display) => ({ ok: true, url, display });
const fail = (error) => ({ ok: false, error });

// PHP trim() only strips ASCII whitespace/NUL; replicate that (JS trim() would also strip NBSP etc.)
const phpTrim = (s) => s.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
const clean = (s) => phpTrim(String(s ?? '').replace(/[\u0000-\u001F\u007F​-‍﻿]/g, ''));
const safeDecode = (s) => { try { return decodeURIComponent(s); } catch { return s; } };

export function hint(platform, kind, linkOnly = false) {
  if (linkOnly) {
    return {
      instagram: 'Paste the link to your Instagram profile. In the app: Profile → Share profile → Copy link.',
      youtube: 'Paste the link to your YouTube channel — youtube.com/@name or youtube.com/channel/UC…',
    }[platform] || 'Paste the link to the account.';
  }
  const k = `${platform}|${kind}`;
  return {
    'instagram|profile': 'Enter your Instagram @username or profile link.',
    'instagram|post': 'Paste the link of the Instagram post or reel.',
    'youtube|channel': 'Enter your YouTube channel link or @handle.',
    'youtube|video': 'Paste the link of the YouTube video.',
  }[k] || 'Enter a username or link.';
}

export function validateTarget(platform, kind, input, linkOnly = false) {
  const raw = clean(input);
  if (raw === '') return fail(hint(platform, kind, linkOnly));
  if ([...raw].length > 300) return fail(linkOnly ? 'That is too long to be a profile or channel link.' : 'That is too long to be a username or link.');
  if (platform === 'instagram') return instagram(kind, raw, linkOnly);
  if (platform === 'youtube') return youtube(kind, raw, linkOnly);
  return fail('This platform is not supported.');
}

/** Returns null (not a URL → bare username), BAD (URL-looking but unacceptable) or {host, segments, query}. */
function asUrl(raw, hosts) {
  let candidate = raw;
  const hasScheme = /^[a-z][a-z0-9+.\-]*:\/\//i.test(candidate);
  if (!hasScheme) {
    if (raw[0] === '@' || !/^[A-Za-z0-9.\-]+\.[A-Za-z]{2,}(\/|$|\?|:)/.test(candidate)) return null;
    candidate = 'https://' + candidate;
  }
  const m = /^([a-z][a-z0-9+.\-]*):\/\/([^/?#]*)([^?#]*)(?:\?([^#]*))?(?:#.*)?$/i.exec(candidate);
  if (!m) return BAD;
  const scheme = m[1].toLowerCase();
  const authority = m[2];
  if (authority === '' || authority.includes('@')) return BAD;             // empty host or user:pass@
  const hp = /^(\[[^\]]*\]|[^:]*)(?::(\d*))?$/.exec(authority);
  if (!hp) return BAD;
  const host = hp[1].toLowerCase().replace(/\.+$/, '');
  const port = hp[2];
  if (!['http', 'https'].includes(scheme) || (port !== undefined && port !== '' && ![80, 443].includes(Number(port))) || !hosts.includes(host)) return BAD;

  const segments = m[3].split('/').filter((s) => s !== '').map(safeDecode);
  const query = {};
  if (m[4]) {
    for (const pair of m[4].split('&')) {
      if (pair === '') continue;
      const i = pair.indexOf('=');
      const k = safeDecode((i < 0 ? pair : pair.slice(0, i)).replace(/\+/g, ' '));
      const v = safeDecode((i < 0 ? '' : pair.slice(i + 1)).replace(/\+/g, ' '));
      if (k.endsWith('[]') || k.includes('[')) { delete query[k.split('[')[0]]; continue; }   // PHP turns these into arrays → ignored
      query[k] = v;                                                                               // last duplicate wins, like parse_str
    }
  }
  return { host, segments, query };
}

const validIgUsername = (n) => /^[A-Za-z0-9._]{1,30}$/.test(n) && /[A-Za-z0-9_]/.test(n);
const looksLikeUsername = (s, max) => new RegExp(`^@?[A-Za-z0-9._]{1,${max}}$`).test(s);

function instagram(kind, raw, linkOnly) {
  const u = asUrl(raw, IG_HOSTS);
  if (u && u.bad) {
    return fail(kind === 'post'
      ? 'That does not look like an Instagram post or reel link.'
      : (linkOnly
        ? 'That is not an Instagram profile link. Use a link like https://www.instagram.com/username/'
        : 'That does not look like an Instagram link. Use instagram.com/yourusername or just your @username.'));
  }
  if (kind === 'post') {
    if (u === null) {
      return fail(looksLikeUsername(raw, 30)
        ? 'This service needs the link of a post or reel, not a username.'
        : 'Paste the full link of the Instagram post or reel.');
    }
    for (const i of [0, 1]) {
      const type = (u.segments[i] || '').toLowerCase();
      const code = u.segments[i + 1] || '';
      if (['p', 'reel', 'reels', 'tv'].includes(type) && /^[A-Za-z0-9_-]{5,40}$/.test(code)) {
        const path = type === 'reels' ? 'reel' : type;
        return ok(`https://www.instagram.com/${path}/${code}/`, `${path}/${code}`);
      }
    }
    return fail('That link does not point to a post or reel. Open the post, tap “Copy link” and paste it here.');
  }
  // profile
  if (u === null) {
    if (linkOnly) {   // the account LINK is required, a bare username is not enough
      return fail(looksLikeUsername(raw, 30)
        ? 'Please paste your full Instagram profile link, not just the username. Example: https://www.instagram.com/username/'
        : 'Paste your Instagram profile link. Example: https://www.instagram.com/username/');
    }
    const name = raw.replace(/^@+/, '');
    if (!validIgUsername(name)) return fail('Enter a valid Instagram username (letters, numbers, dots and underscores) or profile link.');
    return ok(`https://www.instagram.com/${name}/`, `@${name}`);
  }
  let first = u.segments[0] || '';
  if (first.toLowerCase() === '_u' && u.segments[1] !== undefined) first = u.segments[1];   // the app's share link: instagram.com/_u/username
  if (first === '') return fail(linkOnly ? 'That link has no username in it. Use https://www.instagram.com/username/' : 'That link has no username. Use https://instagram.com/yourusername');
  if (IG_RESERVED.includes(first.toLowerCase())) {
    return fail(linkOnly
      ? 'That looks like a post or page link, not a profile. Paste your profile link: https://www.instagram.com/username/'
      : 'That looks like a post or page link. Paste your profile link or @username instead.');
  }
  const name = first.replace(/^@+/, '');
  if (!validIgUsername(name)) return fail('That profile link does not contain a valid Instagram username.');
  return ok(`https://www.instagram.com/${name}/`, `@${name}`);
}

function isChannelPath(u) {
  const first = u.segments[0] || '';
  return (first !== '' && first[0] === '@') || ['channel', 'c', 'user'].includes(first.toLowerCase());
}

function youtube(kind, raw, linkOnly) {
  const u = asUrl(raw, YT_HOSTS);
  if (u && u.bad) {
    return fail(linkOnly && kind === 'channel'
      ? 'That is not a YouTube channel link. Copy the link from YouTube, e.g. https://www.youtube.com/@channelname'
      : 'That does not look like a YouTube link. Copy the link from YouTube and paste it here.');
  }

  if (kind === 'video') {
    if (u === null) return fail('Paste the full link of the YouTube video.');
    let id = null;
    let shorts = false;
    const segs = u.segments;
    if (u.host.endsWith('youtu.be')) {
      id = segs[0] ?? null;
    } else {
      const first = (segs[0] || '').toLowerCase();
      if (first === 'watch') id = u.query.v ?? null;
      else if (['shorts', 'live', 'embed', 'v'].includes(first)) { id = segs[1] ?? null; shorts = first === 'shorts'; }
    }
    if (typeof id === 'string' && /^[A-Za-z0-9_-]{11}$/.test(id)) {
      return shorts ? ok(`https://www.youtube.com/shorts/${id}`, `shorts/${id}`) : ok(`https://www.youtube.com/watch?v=${id}`, `video ${id}`);
    }
    if (isChannelPath(u)) return fail('That is a channel link. This service needs the link of a specific video.');
    return fail('That link does not point to a YouTube video. Open the video, tap Share → Copy link.');
  }

  // channel
  if (u === null) {
    if (linkOnly) {   // the channel LINK is required, a bare @handle is not enough
      return fail(/^@?[A-Za-z0-9._-]{1,30}$/.test(raw)
        ? 'Please paste your full YouTube channel link, not just the @handle. Example: https://www.youtube.com/@channelname'
        : 'Paste your YouTube channel link. Example: https://www.youtube.com/@channelname');
    }
    const handle = raw.replace(/^@+/, '');
    if (!/^[A-Za-z0-9._-]{3,30}$/.test(handle)) return fail('Enter a valid channel @handle or paste your channel link.');
    return ok(`https://www.youtube.com/@${handle}`, `@${handle}`);
  }
  const segs = u.segments;
  const first = segs[0] || '';
  if (u.host.endsWith('youtu.be') || ['watch', 'shorts', 'live', 'embed', 'v'].includes(first.toLowerCase())) {
    return fail(linkOnly
      ? 'That is a video link. Paste your channel link instead, e.g. https://www.youtube.com/@channelname'
      : 'That is a video link. This service needs your channel link or @handle.');
  }
  if (first !== '' && first[0] === '@') {
    const h = first.slice(1);
    if (/^[A-Za-z0-9._-]{3,30}$/.test(h)) return ok(`https://www.youtube.com/@${h}`, `@${h}`);
  } else if (first.toLowerCase() === 'channel' && /^UC[A-Za-z0-9_-]{22}$/.test(segs[1] || '')) {
    return ok(`https://www.youtube.com/channel/${segs[1]}`, `channel/${segs[1]}`);
  } else if (['c', 'user'].includes(first.toLowerCase()) && /^[A-Za-z0-9._-]{1,100}$/.test(segs[1] || '')) {
    return ok(`https://www.youtube.com/${first.toLowerCase()}/${segs[1]}`, `${first.toLowerCase()}/${segs[1]}`);
  } else if (first !== '' && !YT_RESERVED.includes(first.toLowerCase()) && /^[A-Za-z0-9._-]{3,100}$/.test(first)) {
    return ok(`https://www.youtube.com/${first}`, first);
  }
  return fail(linkOnly
    ? 'That link does not look like a YouTube channel. Use https://www.youtube.com/@channelname or https://www.youtube.com/channel/UC…'
    : 'That link does not look like a YouTube channel. Use youtube.com/@yourchannel');
}
