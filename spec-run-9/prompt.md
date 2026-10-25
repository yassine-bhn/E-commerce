# WhatsApp Chat Integration — Backend Specification

## Objective
Implement the backend support required for a WhatsApp contact feature. When a user clicks the WhatsApp button on the frontend, they should be redirected to our official WhatsApp Business chat with an optional prefilled message.

## Functional Requirements

### 1. WhatsApp Configuration
- Store the official WhatsApp contact number in a centralized configuration using environment variables.
- Do not hardcode the WhatsApp number in business logic.
- Provide a configurable default welcome message or initial contact message.
- Ensure the phone number follows the international format required by WhatsApp, without spaces or special characters.

### 2. Backend Endpoint
Implement an endpoint that provides the WhatsApp contact information to the frontend.

**Example:**
`GET /api/contact/whatsapp`

**Response:**
```json
{
  "phoneNumber": "216XXXXXXXX",
  "message": "Hello, I would like more information about your products.",
  "chatUrl": "https://wa.me/216XXXXXXXX?text=Hello%2C%20I%20would%20like%20more%20information%20about%20your%20products."
}
```

The values above are examples and must be replaced with the actual configuration.

### 3. Chat URL Generation
- Generate the WhatsApp chat URL dynamically from the configured phone number.
- URL-encode the prefilled message correctly.
- Use the official `https://wa.me/` link format.
- Support an optional contextual message based on the page or product the user is viewing, if applicable.
- Validate any contextual parameters to prevent malformed URLs or injection issues.

### 4. Security and Validation
- Validate the configured phone number at application startup or configuration validation time.
- Never expose private credentials, API tokens, or other secrets.
- Do not accept arbitrary redirect URLs from the client.
- Only generate links targeting the official WhatsApp domain.
- Apply the project's existing API authentication and rate-limiting conventions where appropriate.

### 5. Business Logic
- The backend must generate and return the WhatsApp contact details.
- Clicking the WhatsApp button opens the official WhatsApp chat for the configured business number.
- The message may be prefilled, but **the user must explicitly send it inside WhatsApp**.
- Do not implement message delivery through the WhatsApp Business API unless explicitly required.
- No database entities, migrations, or message persistence are needed for this basic integration.

## Acceptance Criteria
1. The endpoint returns the configured WhatsApp number and a valid chat URL.
2. The chat URL opens the correct official WhatsApp business conversation.
3. The default message is correctly URL-encoded and prefilled.
4. Invalid phone numbers and malformed contextual parameters are handled safely.
5. Changing the WhatsApp number or default message requires configuration changes only.
6. The implementation follows the existing backend architecture, coding standards, and error-handling conventions.
7. No frontend implementation is included in this task.

## Scope Restrictions
- Backend only.
- Do not implement a custom real-time chat system.
- Do not integrate the WhatsApp Business API.
- Do not store conversations or messages.
- Do not modify frontend components.
- Reuse existing configuration, routing, validation, and testing conventions.
