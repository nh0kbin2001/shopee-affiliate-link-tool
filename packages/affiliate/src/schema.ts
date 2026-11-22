import { z } from 'zod';

/** Offer IDs are opaque slugs; they are the only thing the LLM is allowed to emit. */
export const OfferIdSchema = z.string().regex(/^[A-Za-z0-9_-]{1,64}$/, 'invalid offerId');

export const GeoSchema = z.object({
  lat: z.number().min(-90).max(90),
  lng: z.number().min(-180).max(180),
});

export const OfferSchema = z.object({
  offerId: OfferIdSchema,
  partner: z.string().min(1).max(40),
  deeplink: z
    .string()
    .url()
    .refine((u) => URL.canParse(u) && new URL(u).protocol === 'https:', 'deeplink must be https'),
  geo: GeoSchema,
  priceVnd: z.number().int().nonnegative(),
  currency: z.string().regex(/^[A-Z]{3}$/, 'ISO 4217 code'),
  lastVerifiedAt: z.coerce.date(),
});

export type Offer = z.infer<typeof OfferSchema>;
export type Geo = z.infer<typeof GeoSchema>;
