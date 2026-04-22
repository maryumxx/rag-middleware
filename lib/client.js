export function getClient() {
  const apiKey = process.env.API_KEY;

  if (!apiKey) {
    throw new Error("API_KEY is missing");
  }

  return new SomeClient(apiKey);
}
