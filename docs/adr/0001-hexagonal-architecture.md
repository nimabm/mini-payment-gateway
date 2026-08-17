# 1. Hexagonal architecture with a driver port for PSPs

**Status:** accepted

## Context

The gateway sits between several websites and several payment providers. The
providers will change: accounts get closed, new banks get added, a provider has
a bad month and traffic needs moving. The websites must not notice any of it.

The obvious shortcut is a `PaymentService` with a `switch` on the provider name.
It works for two providers and becomes unmaintainable at five, because every
provider's vocabulary leaks into shared code.

## Decision

Four layers, dependencies pointing inward: `Presentation → Application →
Domain`, with `Infrastructure` implementing interfaces the inner layers declare.

Every PSP integration goes through one interface,
`Application\Gateway\PaymentGatewayDriver`, with four methods: `purchase`,
`verify`, `inquire`, `refund`.

Two supporting choices:

- **`DriverName` is a value object, not an enum.** An enum would force an edit
  to the domain every time a bank is added, which is the coupling this exists
  to prevent.
- **Drivers return failures, they do not throw them.** A declined amount is a
  normal outcome the router must inspect and route around. Exceptions are for
  genuine faults, and the checkout handler catches those too rather than
  letting one broken driver fail a sale.

## Consequences

Adding a provider is one class and one line in `config/drivers.php`.

The cost is indirection: reading a payment end to end means opening a handler,
an interface and a driver. For a system whose entire purpose is provider
independence, that is the right trade.
