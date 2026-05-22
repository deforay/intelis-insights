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
  "Show VL tests by province for the last 6 months",
  "Compare VL testing volume by district this year",
  "Show monthly rejected sample counts this year",
  "Which testing labs have the lowest rejection rate this year?",
  "Show EID testing trend over the last 12 months",
  "Compare EID tests this quarter to last quarter",
  "Show VL suppression rate by testing lab this year",
  "Which districts have the lowest VL suppression rate?",
  "Show high viral load counts by district this quarter",
  "Compare high viral load counts by province this year",
  "Show average TAT by district last quarter",
  "Compare TAT by testing lab for the last 6 months",
  "Which testing labs improved TAT the most this year?",
  "Show monthly average TAT for VL tests this year",
  "How many VL samples were rejected last month?",
  "Show rejected sample rate by province this year",
  "Which facilities submitted the most rejected VL samples?",
  "Compare rejected samples this month to last month",
  "Show VL tests by requesting facility this quarter",
  "Which facilities submitted the most EID samples this year?",
  "Show VL testing volume by sex this quarter",
  "Compare VL tests by sex over the last 12 months",
  "Show suppression rate by sex this year",
  "How many high VL results were reported last month?",
  "Show high VL rate by province this year",
  "Compare VL testing volume by testing lab this quarter",
  "Show monthly VL tests and rejected samples this year",
  "Which provinces processed the most VL tests last quarter?",
  "Show EID rejected sample rate by testing lab this year",
  "Which districts sent the most VL samples this month?",
  "Show VL testing volume by province this quarter",
  "Compare VL suppression rate this quarter to last quarter",
  "Show facilities with no VL tests in the last month",
  "Which testing labs processed VL samples fastest last quarter?",
  "Show TAT outlier-safe average by province this year",
  "How many EID samples were rejected this quarter?",
  "Show monthly EID rejected samples this year",
  "Compare EID testing volume by district this quarter",
  "Which provinces have the highest rejected sample rate?",
  "Show VL tests by month for the last 24 months",
  "Compare testing volume across VL and EID this year",
  "Show rejected samples by test type this quarter",
  "Which labs processed the most samples last month?",
  "Show average TAT for EID tests by testing lab",
  "Compare average TAT this month to last month",
  "Show suppression rate trend over the last 12 months",
  "Which provinces had zero rejected samples last month?",
  "Show facilities with high VL counts this quarter",
  "Compare VL testing volume by facility type this year",
  "Show testing lab workload by month this year",
  "Which districts improved suppression rate this year?",
  "Show VL sample testing volume by facility last month",
  "Compare rejected sample rates across testing labs",
  "Show EID tests by province over the last 6 months",
  "How many VL tests were completed this week?",
  "Show weekly rejected sample counts for the last 8 weeks",
  "Which testing labs had no rejected samples this quarter?",
  "Show average TAT by requesting facility this quarter",
  "Compare high VL counts this month to last month",
  "Show VL suppression rate by month for the last year",
  "Which provinces sent the most EID samples this quarter?",
  "Show VL testing volume by lab and month this year",
  "Compare sample testing volumes by province for the last 6 months",
  "Show rejected VL samples by facility this quarter",
  "Which districts have the longest average TAT?",
  "Show EID testing volume by testing lab last month",
  "Compare VL and EID rejected sample rates this year",
  "Show high viral load counts by testing lab this quarter",
  "Which facilities have the highest VL testing volume?",
  "Show monthly testing volume by test type this year",
  "Compare suppression rate by province for the last 4 quarters",
  "Show TAT by month and testing lab for the last 6 months",
] as const;

export const DEFAULT_EMPTY_STATE_SUGGESTIONS =
  EMPTY_STATE_SUGGESTION_POOL.slice(0, SUGGESTIONS_PER_ROTATION);

export type SuggestionCategory = "Volume" | "Quality" | "TAT" | "Suppression";

export function getEmptyStateSuggestions(seed = Math.random()): string[] {
  const start = Math.floor(seed * EMPTY_STATE_SUGGESTION_POOL.length);
  const step = pickCoprimeStep(start);

  return Array.from({ length: SUGGESTIONS_PER_ROTATION }, (_, offset) => {
    const index =
      (start + offset * step) % EMPTY_STATE_SUGGESTION_POOL.length;
    return EMPTY_STATE_SUGGESTION_POOL[index];
  });
}

export function getSuggestionCategory(question: string): SuggestionCategory {
  const q = question.toLowerCase();
  if (/\btat\b|turnaround/.test(q)) return "TAT";
  if (/suppression|suppressed|high viral load/.test(q)) return "Suppression";
  if (/reject/.test(q)) return "Quality";
  return "Volume";
}

function pickCoprimeStep(start: number): number {
  const candidates = [5, 7, 11, 13, 17, 19, 23];
  return candidates[start % candidates.length];
}
