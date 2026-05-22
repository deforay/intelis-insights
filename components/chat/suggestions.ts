const SUGGESTIONS_PER_ROTATION = 4;

export const EMPTY_STATE_SUGGESTION_POOL = [
  "How many VL tests were done last month?",
  "Show suppression rate by province",
  "What's the average turnaround time per testing lab?",
  "Number of rejected samples this quarter",
  "Show VL testing volume trend over the last 12 months",
  "Which provinces have the lowest suppression rate this year?",
  "Compare VL tests this month to last month",
  "Show rejected sample rate by testing lab",
  "Average turnaround time by province last quarter",
  "Which facilities sent the most VL samples this month?",
  "Show monthly VL suppression trend this year",
  "How many EID tests were processed last month?",
  "Show EID testing volume by facility this quarter",
  "Which districts had the most rejected samples this year?",
  "Compare sample testing volumes by testing lab for the last 6 months",
  "Show high viral load counts by province this quarter",
  "What percentage of VL tests were suppressed last month?",
  "Which testing labs have the longest average TAT?",
  "Show VL tests by sex over the last year",
  "How many samples were collected but not yet tested?",
  "Show requesting facilities with the highest rejection rate",
  "Compare VL suppression rate by district last quarter",
  "Show weekly VL testing volume for the last 8 weeks",
  "Which labs processed the most rejected samples this year?",
] as const;

export const DEFAULT_EMPTY_STATE_SUGGESTIONS =
  EMPTY_STATE_SUGGESTION_POOL.slice(0, SUGGESTIONS_PER_ROTATION);

export function getEmptyStateSuggestions(seed = Math.random()): string[] {
  const start = Math.floor(seed * EMPTY_STATE_SUGGESTION_POOL.length);
  const step = pickCoprimeStep(start);

  return Array.from({ length: SUGGESTIONS_PER_ROTATION }, (_, offset) => {
    const index =
      (start + offset * step) % EMPTY_STATE_SUGGESTION_POOL.length;
    return EMPTY_STATE_SUGGESTION_POOL[index];
  });
}

function pickCoprimeStep(start: number): number {
  const candidates = [5, 7, 11, 13, 17, 19, 23];
  return candidates[start % candidates.length];
}
