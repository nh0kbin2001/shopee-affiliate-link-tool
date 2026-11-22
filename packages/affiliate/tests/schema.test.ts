import { describe, expect, it } from 'vitest';
import { OfferSchema } from '../src/schema.js';
import { validOffer } from './fixtures.js';

describe('OfferSchema', () => {
  it('accepts a valid offer and coerces lastVerifiedAt to Date', () => {
    const o = OfferSchema.parse(validOffer());
    expect(o.lastVerifiedAt).toBeInstanceOf(Date);
  });

  it.each(['not a url', 'ftp://x.example/a', 'http://insecure.example/a', 'javascript:alert(1)', ''])(
    'rejects bad deeplink %j',
    (deeplink) => {
      expect(OfferSchema.safeParse(validOffer({ deeplink })).success).toBe(false);
    },
  );

  it('rejects missing geo', () => {
    const { geo: _geo, ...noGeo } = validOffer();
    expect(OfferSchema.safeParse(noGeo).success).toBe(false);
  });

  it('rejects out-of-range geo', () => {
    expect(OfferSchema.safeParse(validOffer({ geo: { lat: 91, lng: 0 } })).success).toBe(false);
  });

  it('rejects negative price', () => {
    expect(OfferSchema.safeParse(validOffer({ priceVnd: -1 })).success).toBe(false);
  });

  it('rejects a URL used as offerId', () => {
    expect(OfferSchema.safeParse(validOffer({ offerId: 'https://evil.example' })).success).toBe(false);
  });
});
