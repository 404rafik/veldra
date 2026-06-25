import type { FastifyInstance, FastifyRequest } from 'fastify';

/**
 * Schema for incoming analytics data from Premium WordPress sites.
 * Only pre-aggregated, anonymised data is transmitted — no raw pageviews.
 */
interface TrackPayload {
  site_url: string;
  date: string;
  data: Array<{
    path: string;
    country_code: string;
    device_type: string;
    browser_family: string;
    referrer_host: string;
    utm_source: string;
    utm_campaign: string;
    sessions: number;
    pageviews: number;
    bounces: number;
    total_duration_ms?: number;
  }>;
}

/**
 * POST /api/v1/track
 *
 * Receives anonymised, pre-aggregated analytics data from Premium
 * WordPress sites. JWT-authenticated. Data is upserted into the
 * veldra_daily_summary table.
 */
export async function trackRoute(app: FastifyInstance): Promise<void> {
  app.post(
    '/track',
    {
      schema: {
        body: {
          type: 'object',
          required: ['site_url', 'date', 'data'],
          properties: {
            site_url: { type: 'string' },
            date: { type: 'string', pattern: '^\\d{4}-\\d{2}-\\d{2}$' },
            data: {
              type: 'array',
              items: {
                type: 'object',
                required: ['path', 'sessions', 'pageviews'],
                properties: {
                  path: { type: 'string' },
                  country_code: { type: 'string', maxLength: 2 },
                  device_type: { type: 'string' },
                  browser_family: { type: 'string' },
                  referrer_host: { type: 'string' },
                  utm_source: { type: 'string' },
                  utm_campaign: { type: 'string' },
                  sessions: { type: 'number' },
                  pageviews: { type: 'number' },
                  bounces: { type: 'number' },
                  total_duration_ms: { type: 'number' },
                },
              },
            },
          },
        },
      },
      preHandler: app.auth([app.verifyJWT]),
    },
    async (request: FastifyRequest<{ Body: TrackPayload }>, reply) => {
      const { site_url, date, data } = request.body;

      if (!data || data.length === 0) {
        return reply.code(400).send({ error: 'No data provided' });
      }

      if (data.length > 10000) {
        return reply.code(413).send({ error: 'Payload too large (max 10,000 rows)' });
      }

      // Validate site_url matches the authenticated token
      const tokenPayload = request.user as { site?: string };
      if (tokenPayload.site && tokenPayload.site !== site_url) {
        return reply.code(403).send({ error: 'Site URL does not match token' });
      }

      // In production, data would be upserted via Drizzle into PostgreSQL.
      // For MVP, acknowledge receipt.
      app.log.info(`Received ${data.length} rows for ${site_url} on ${date}`);

      return reply.code(202).send({
        accepted: true,
        rows: data.length,
        site: site_url,
        date,
      });
    },
  );
}
