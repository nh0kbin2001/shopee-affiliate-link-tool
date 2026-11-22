import { describe, expect, it } from 'vitest';
import { OfferRegistry } from '../src/registry.js';
import { validOffer } from './fixtures.js';

describe('OfferRegistry', () => {
  it('adds and gets offers; rejects invalid ones', () => {
    const r = new OfferRegistry();
    r.addOffer(validOffer());
    expect(r.getOffer('klook-hcm-cuchi')?.partner).toBe('klook');
    expect(r.getOffer('missing')).toBeUndefined();
    expect(() => r.addOffer(validOffer({ priceVnd: -5 }))).toThrow();
    expect(r.size).toBe(1);
  });

  it('lists offers within radius, nearest first', () => {
    const r = new OfferRegistry();
    r.addOffer(validOffer({ offerId: 'ben-thanh', geo: { lat: 10.7725, lng: 106.698 } })); // HCMC center
    r.addOffer(validOffer({ offerId: 'cu-chi', geo: { lat: 11.1414, lng: 106.4621 } })); // ~50 km
    r.addOffer(validOffer({ offerId: 'hanoi', geo: { lat: 21.0285, lng: 105.8542 } })); // ~1100 km
    expect(r.listOffersNear(10.7769, 106.7009, 60).map((o) => o.offerId)).toEqual(['ben-thanh', 'cu-chi']);
    expect(r.listOffersNear(10.7769, 106.7009, 5).map((o) => o.offerId)).toEqual(['ben-thanh']);
    expect(() => r.listOffersNear(Number.NaN, 0, 1)).toThrow(RangeError);
  });
});
