export const dynamic = "force-dynamic";

export async function GET() {
  return Response.json({
    status: "ok",
    service: "intelis-insights",
    timestamp: new Date().toISOString(),
  });
}
