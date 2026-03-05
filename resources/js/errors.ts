export class ServerValidationError extends Error {
  public readonly errors: Record<string, string[]>;

  constructor(message: string, errors: Record<string, string[]>) {
    super(message);
    this.name = "ServerValidationError";
    this.errors = errors;
  }
}

export class ServerAuthenticationError extends Error {
  constructor(message: string = "Unauthenticated.") {
    super(message);
    this.name = "ServerAuthenticationError";
  }
}

export class ServerSessionExpiredError extends Error {
  constructor(message: string = "Your session has expired. Please refresh the page.") {
    super(message);
    this.name = "ServerSessionExpiredError";
  }
}
