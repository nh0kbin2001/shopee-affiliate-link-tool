export const validOffer = (over: Record<string, unknown> = {}) => ({
  offerId: 'klook-hcm-cuchi',
  partner: 'klook',
  deeplink: 'https://affiliate.example/klook/cuchi?aid=1',
  geo: { lat: 11.1414, lng: 106.4621 },
  priceVnd: 650000,
  currency: 'VND',
  lastVerifiedAt: '2026-11-20T00:00:00Z',
  ...over,
});
