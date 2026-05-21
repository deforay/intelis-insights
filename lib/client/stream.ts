/**
 * NDJSON stream parser for the API's QueryEvent stream.
 *
 * Yields one parsed JSON object per newline-delimited record. Tolerates
 * incomplete final lines until the stream closes.
 */

export async function* parseNdjsonStream<T = unknown>(
  stream: ReadableStream<Uint8Array>,
): AsyncGenerator<T> {
  const reader = stream.getReader();
  const decoder = new TextDecoder();
  let buffer = "";
  try {
    while (true) {
      const { value, done } = await reader.read();
      if (value) {
        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split("\n");
        buffer = lines.pop() ?? "";
        for (const line of lines) {
          const trimmed = line.trim();
          if (!trimmed) continue;
          yield JSON.parse(trimmed) as T;
        }
      }
      if (done) {
        const tail = buffer.trim();
        if (tail) yield JSON.parse(tail) as T;
        break;
      }
    }
  } finally {
    reader.releaseLock();
  }
}
