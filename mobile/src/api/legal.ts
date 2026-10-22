import { apiRequest } from './client';
import type { LegalDocument } from '../types';

export function fetchCurrentLegalDocuments() {
  return apiRequest<{ data: LegalDocument[] }>('/legal/current', { authenticated: false });
}

type AcceptanceStatusResponse = {
  data: { needs_acceptance: boolean; outstanding: LegalDocument[] };
};

export function fetchLegalAcceptanceStatus() {
  return apiRequest<AcceptanceStatusResponse>('/legal/acceptance-status');
}

export function acceptCurrentLegalDocuments() {
  return apiRequest<{ data: { accepted: boolean } }>('/legal/accept', { method: 'POST' });
}
