import Fastify from 'fastify';
import cors from '@fastify/cors';
import rateLimit from '@fastify/rate-limit';
import jwt from '@fastify/jwt';
import { trackRoute } from './routes/track.js';
import 'dotenv/config';

const PORT = parseInt(process.env.PORT || '3000', 10);
const HOST = process.env.HOST || '0.0.0.0';

const jwtSecret = process.env.JWT_SECRET || '';
if (!jwtSecret) {
  throw new Error('JWT_SECRET environment variable is required');
}

const app = Fastify({ logger: true });

await app.register(cors, { origin: false });
await app.register(rateLimit, { max: 200, timeWindow: '1 minute' });
await app.register(jwt, { secret: jwtSecret, sign: { algorithm: 'RS256' } });

app.register(trackRoute, { prefix: '/api/v1' });

app.get('/health', async () => ({ status: 'ok', timestamp: new Date().toISOString() }));

try {
  await app.listen({ port: PORT, host: HOST });
  app.log.info('Veldra Cloud running on ' + HOST + ':' + PORT);
} catch (err) {
  app.log.error(err);
  process.exit(1);
}
