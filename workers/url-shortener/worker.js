export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (request.method === 'POST' && url.pathname.replace(/\/+$/, '') === '/shorten') {
      return handleShorten(request, env, url);
    }

    if (request.method === 'GET') {
      const slug = url.pathname.replace(/^\/+/, '');
      if (slug) {
        const destination = await env.LINKS.get(`slug:${slug}`);
        if (destination) {
          return Response.redirect(destination, 302);
        }
      }
      return new Response('Not Found', { status: 404 });
    }

    return new Response('Method Not Allowed', {
      status: 405,
      headers: { 'Allow': 'GET, POST' },
    });
  },
};

async function handleShorten(request, env, url) {
  if (env.API_TOKEN) {
    const header = request.headers.get('Authorization') || '';
    const token = header.startsWith('Bearer ') ? header.slice(7).trim() : '';
    if (token !== env.API_TOKEN) {
      return json({ error: 'Unauthorized' }, 401);
    }
  }

  let body;
  try {
    body = await request.json();
  } catch (error) {
    return json({ error: 'Invalid JSON body' }, 400);
  }

  const destination = typeof body?.url === 'string' ? body.url.trim() : '';
  if (!destination) {
    return json({ error: 'Missing url' }, 400);
  }

  const normalized = normalizeUrl(destination);
  if (!normalized) {
    return json({ error: 'Invalid url' }, 400);
  }

  const hashKey = await createHash(normalized);
  const existingSlug = await env.LINKS.get(`hash:${hashKey}`);
  if (existingSlug) {
    return json(buildResponse(normalized, existingSlug, env));
  }

  let slug;
  for (let attempts = 0; attempts < 5; attempts++) {
    slug = randomSlug();
    const used = await env.LINKS.get(`slug:${slug}`);
    if (!used) {
      break;
    }
    slug = null;
  }

  if (!slug) {
    return json({ error: 'Failed to allocate slug' }, 500);
  }

  await env.LINKS.put(`slug:${slug}`, normalized);
  await env.LINKS.put(`hash:${hashKey}`, slug);

  return json(buildResponse(normalized, slug, env));
}

function normalizeUrl(input) {
  try {
    const parsed = new URL(input);
    if (!parsed.protocol.startsWith('http')) {
      return '';
    }
    parsed.hash = '';
    return parsed.toString();
  } catch (error) {
    return '';
  }
}

function buildResponse(url, slug, env) {
  const domain = (env.SHORT_DOMAIN || 'https://240jv.link').replace(/\/+$/, '');
  return {
    url,
    slug,
    short_url: `${domain}/${slug}`,
  };
}

function randomSlug(length = 7) {
  const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
  const randomValues = crypto.getRandomValues(new Uint8Array(length));
  let slug = '';
  for (const value of randomValues) {
    slug += alphabet[value % alphabet.length];
  }
  return slug;
}

async function createHash(value) {
  const buffer = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
  const bytes = new Uint8Array(buffer).slice(0, 8);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

function json(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      'Content-Type': 'application/json',
      'Access-Control-Allow-Origin': '*',
    },
  });
}
