import { describe, expect, it, vi } from 'vitest';
import { OfferRegistry } from '../src/registry.js';
import { resolveItinerary, stripUrls } from '../src/resolver.js';
import { validOffer } from './fixtures.js';

const REAL = ['klook-cuchi', 'agoda-d1-hotel', 'gyg-mekong'];
const linkFor = (id: string, i: number) => `https://affiliate.example/go/${id}?n=${i}`;

function registry(): OfferRegistry {
  const r = new OfferRegistry();
  REAL.forEach((id, i) => r.addOffer(validOffer({ offerId: id, deeplink: linkFor(id, i) })));
  return r;
}

const RAW_URLS = ['https://evil.example/steal', 'http://phish.example/x', 'www.fake-deal.com/promo', 'bit.ly/abc123'];

// 3 real offer_ids + 2 fake ones, with raw URLs hidden in extra fields, titles and IDs.
const itinerary = {
  days: [
    {
      day: 1,
      items: [
        { offer_id: 'klook-cuchi', title: 'Cu Chi tunnels, book at https://evil.example/steal', url: RAW_URLS[0] },
        { offer_id: 'fake-offer-1', title: 'Hidden gem', deeplink: RAW_URLS[1] },
        { offer_id: 'agoda-d1-hotel', title: 'Hotel www.fake-deal.com/promo', link: RAW_URLS[2] },
      ],
    },
    {
      day: 2,
      items: [
        { offer_id: 'https://bit.ly/abc123', title: 'raw url as id' },
        { offer_id: 'gyg-mekong', title: 'Mekong day trip bit.ly/abc123' },
      ],
    },
  ],
};

describe('resolveItinerary', () => {
  it('maps 3 real offer_ids to registry deeplinks and drops + logs 2 fake ones', () => {
    const log = vi.fn();
    const out = resolveItinerary(itinerary, registry(), log);
    const items = out.days.flatMap((d) => d.items);
    expect(items.map((i) => i.offerId)).toEqual(REAL);
    expect(items.map((i) => i.deeplink)).toEqual(REAL.map(linkFor));
    expect(out.dropped.map((d) => d.offerId)).toEqual(['fake-offer-1', 'https://bit.ly/abc123']);
    expect(log).toHaveBeenCalledTimes(2);
  });

  it('lets 0 raw URLs from the input reach the resolved itinerary', () => {
    const out = resolveItinerary(JSON.stringify(itinerary), registry(), () => {});
    const resolved = JSON.stringify(out.days);
    for (const raw of RAW_URLS) expect(resolved).not.toContain(raw.replace(/^https?:\/\//, ''));
    const urls = resolved.match(/https?:\/\/[^"\s]+/g) ?? [];
    expect(urls).toEqual(REAL.map(linkFor));
  });

  it('rejects malformed itineraries', () => {
    expect(() => resolveItinerary({ days: 'nope' }, registry(), () => {})).toThrow();
  });
});

describe('stripUrls', () => {
  it('removes URL-like substrings', () => {
    expect(stripUrls('see https://a.example/x and www.b.com or c.vn/p now')).toBe('see and or now');
  });
});
