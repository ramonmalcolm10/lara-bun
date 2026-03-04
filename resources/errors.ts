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

export class ServerAuthorizationError extends Error {
  constructor(message: string = "This action is unauthorized.") {
    super(message);
    this.name = "ServerAuthorizationError";
  }
}

export class ServerRedirectError extends Error {
  public readonly location: string;

  constructor(location: string) {
    super(`Redirect to ${location}`);
    this.name = "ServerRedirectError";
    this.location = location;
  }
}
