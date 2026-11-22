import { z } from 'zod';
import type { OfferRegistry } from './registry.js';

/**
 * Itinerary as produced by the LLM. Items reference offers ONLY by offer_id.
 * Any other field (including URLs the model may have invented) is stripped by zod.
 */
const ItineraryInputSchema = z.object({
  days: z
    .array(
      z.object({
        day: z.number().int().positive(),
        items: z.array(z.object({ offer_id: z.string(), title: z.string().max(200).optional() })).max(100),
      }),
    )
    .max(60),
});

export interface ResolvedItem {
  offerId: string;
  partner: string;
  /** Always taken from the registry, never from the input. */
  deeplink: string;
  title?: string;
}

export interface ResolvedItinerary {
  days: { day: number; items: ResolvedItem[] }[];
  dropped: { day: number; offerId: string; reason: 'unknown_offer' }[];
}

export type Logger = (msg: string, meta: Record<string, unknown>) => void;

const URL_LIKE =
  /\b(?:https?:\/\/|www\.)\S+|\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|vn|io|co|ly|me|link|ee)(?:\/\S*)?/gi;

/** Removes anything that looks like a URL from free text, so titles can't smuggle links. */
export function stripUrls(text: string): string {
  return text.replace(URL_LIKE, '').replace(/\s{2,}/g, ' ').trim();
}

/**
 * Maps every offer_id to its registry deeplink. Unknown IDs are dropped and logged.
 * The output is built from scratch: nothing from the input reaches it except a
 * registry-matched offer ID and a URL-stripped title. `dropped` lists rejected IDs
 * for logging/debugging and must not be rendered to users.
 */
export function resolveItinerary(
  json: unknown,
  registry: OfferRegistry,
  log: Logger = (msg, meta) => console.warn(msg, meta),
): ResolvedItinerary {
  const input = ItineraryInputSchema.parse(typeof json === 'string' ? JSON.parse(json) : json);
  const out: ResolvedItinerary = { days: [], dropped: [] };
  for (const day of input.days) {
    const items: ResolvedItem[] = [];
    for (const item of day.items) {
      const offer = registry.getOffer(item.offer_id);
      if (!offer) {
        const offerId = item.offer_id.slice(0, 64);
        out.dropped.push({ day: day.day, offerId, reason: 'unknown_offer' });
        log('affiliate.resolve: dropped unknown offer_id', { day: day.day, offerId });
        continue;
      }
      const resolved: ResolvedItem = { offerId: offer.offerId, partner: offer.partner, deeplink: offer.deeplink };
      const title = item.title !== undefined ? stripUrls(item.title) : '';
      if (title) resolved.title = title;
      items.push(resolved);
    }
    out.days.push({ day: day.day, items });
  }
  return out;
}
