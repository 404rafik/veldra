import { pgTable, bigint, varchar, date, index, uniqueIndex } from 'drizzle-orm/pg-core';

/**
 * Veldra Cloud — PostgreSQL schema.
 *
 * Mirrors the WordPress plugin's veldra_daily_summary table structure.
 * This is the only cloud-side table — raw pageviews never leave the
 * WordPress server. Only pre-aggregated, anonymised data is synced.
 */

/** Daily aggregated analytics data (anonymised, no PII). */
export const dailySummary = pgTable(
  'veldra_daily_summary',
  {
    date: date('date').notNull(),
    path: varchar('path', { length: 2048 }).notNull().default(''),
    countryCode: varchar('country_code', { length: 2 }).notNull().default(''),
    deviceType: varchar('device_type', { length: 10 }).notNull().default(''),
    browserFamily: varchar('browser_family', { length: 50 }).notNull().default(''),
    referrerHost: varchar('referrer_host', { length: 255 }).notNull().default(''),
    utmSource: varchar('utm_source', { length: 255 }).notNull().default(''),
    utmCampaign: varchar('utm_campaign', { length: 255 }).notNull().default(''),
    sessions: bigint('sessions', { mode: 'number' }).notNull().default(0),
    pageviews: bigint('pageviews', { mode: 'number' }).notNull().default(0),
    bounces: bigint('bounces', { mode: 'number' }).notNull().default(0),
    totalDurationMs: bigint('total_duration_ms', { mode: 'number' }).notNull().default(0),
  },
  (table) => ({
    pk: uniqueIndex('pk_daily_summary').on(
      table.date,
      table.path,
      table.countryCode,
      table.deviceType,
      table.browserFamily,
      table.referrerHost,
      table.utmSource,
      table.utmCampaign,
    ),
    dateIdx: index('idx_cloud_date').on(table.date),
  }),
);

/** Registered WordPress sites (premium accounts). */
export const sites = pgTable('veldra_sites', {
  id: bigint('id', { mode: 'number' }).primaryKey().generatedByDefaultAsIdentity(),
  siteUrl: varchar('site_url', { length: 500 }).notNull().unique(),
  apiKey: varchar('api_key', { length: 64 }).notNull(),
  tier: varchar('tier', { length: 20 }).notNull().default('growth'),
  createdAt: date('created_at').notNull().defaultNow(),
});
