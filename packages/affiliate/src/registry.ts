import { OfferSchema, type Offer } from './schema.js';

const EARTH_RADIUS_KM = 6371;

/** Great-circle (haversine) distance in km. */
export function distanceKm(a: { lat: number; lng: number }, b: { lat: number; lng: number }): number {
  const rad = (d: number) => (d * Math.PI) / 180;
  const dLat = rad(b.lat - a.lat);
  const dLng = rad(b.lng - a.lng);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
  return 2 * EARTH_RADIUS_KM * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** In-memory offer registry. Every offer is validated on the way in. */
export class OfferRegistry {
  private readonly offers = new Map<string, Offer>();

  /** Validates and stores an offer (replaces one with the same offerId). Throws on invalid input. */
  addOffer(input: unknown): Offer {
    const offer = OfferSchema.parse(input);
    this.offers.set(offer.offerId, offer);
    return offer;
  }

  getOffer(offerId: string): Offer | undefined {
    return this.offers.get(offerId);
  }

  /** Offers within radiusKm of (lat, lng), nearest first. */
  listOffersNear(lat: number, lng: number, radiusKm: number): Offer[] {
    if (![lat, lng, radiusKm].every(Number.isFinite) || radiusKm < 0) throw new RangeError('invalid lat/lng/radius');
    return [...this.offers.values()]
      .map((o) => ({ o, d: distanceKm({ lat, lng }, o.geo) }))
      .filter((x) => x.d <= radiusKm)
      .sort((x, y) => x.d - y.d)
      .map((x) => x.o);
  }

  get size(): number {
    return this.offers.size;
  }
}
