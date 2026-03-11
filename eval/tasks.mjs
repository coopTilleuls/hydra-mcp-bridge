// 19 evaluation tasks from EVAL_PROTOCOL.md
// Each task: { id, tier, prompt, negativeCapability, goal }

export const TASKS = [
  // === Tier 1 — Single-resource read ===
  {
    id: 'T1.1',
    tier: 1,
    prompt: 'Is there a book by Dan Simmons in the book API?',
    negativeCapability: false,
    goal: {
      type: 'response_contains',
      values: ['Hyperion'],
    },
  },
  {
    id: 'T1.2',
    tier: 1,
    prompt: "What is the rating of 'Hyperion' in the book API?",
    negativeCapability: false,
    goal: {
      type: 'response_contains_any',
      // Rating is computed from reviews — the fixture has 31 reviews on Hyperion.
      // The agent just needs to return a numeric rating value.
      values: ['rating'],
    },
  },
  {
    id: 'T1.3',
    tier: 1,
    prompt: 'Show me all books by Gordon R. Dickson in the book API.',
    negativeCapability: false,
    goal: {
      type: 'response_contains',
      values: ['Gordon R. Dickson'],
    },
  },
  {
    id: 'T1.4',
    tier: 1,
    prompt: "How many reviews does 'Hyperion' have in the book API?",
    negativeCapability: false,
    goal: {
      // Agent must find Hyperion, then navigate to its reviews sub-collection
      // and report hydra:totalItems (31 reviews in fixture: 30 random + 1 known).
      type: 'response_match',
      check: 'manual',
    },
  },
  {
    id: 'T1.5',
    tier: 1,
    prompt: 'Can you find all books published after 2000 in the book API?',
    negativeCapability: true,
    goal: {
      type: 'response_indicates_limitation',
      keywords: [
        'no filter',
        'not available',
        'no publication year',
        'cannot filter',
        "doesn't support",
        'does not support',
        'no date',
        'no year',
        'not possible',
        "can't filter",
        'no such filter',
        'only supports',
        'only available',
        'author and title',
      ],
    },
  },

  // === Tier 2 — Single mutation ===
  {
    id: 'T2.1',
    tier: 2,
    prompt:
      "Add a book to the book API with Open Library ID OL1168083M and set its condition to 'good'.",
    negativeCapability: false,
    goal: {
      type: 'api_state',
      method: 'GET',
      url: '/books?book=/books/OL1168083M',
      expect: { 'hydra:totalItems_gte': 1 },
    },
  },
  {
    id: 'T2.2',
    tier: 2,
    prompt: "Delete the book 'Dragons of Deltora' by Emily Rodda from the book API.",
    negativeCapability: false,
    goal: {
      type: 'api_state',
      method: 'GET',
      url: '/books?title=Dragons+of+Deltora',
      expect: { 'hydra:totalItems': 0 },
    },
  },
  {
    id: 'T2.3',
    tier: 2,
    prompt: "Using the book API, change the condition of the 'Hyperion' Book to 'good'.",
    negativeCapability: false,
    goal: {
      type: 'api_state',
      method: 'GET',
      url: '/books?title=Hyperion',
      expect: { member_condition_contains: 'Good' },
    },
  },
  {
    id: 'T2.4',
    tier: 2,
    prompt:
      "Using the book API, write a review for 'Hyperion': body 'A masterpiece of science fiction', rating 5.",
    negativeCapability: false,
    goal: {
      type: 'api_state',
      method: 'GET',
      url: '/reviews?book.title=Hyperion',
      expect: { member_body_contains: 'masterpiece' },
    },
  },
  {
    id: 'T2.5',
    tier: 2,
    prompt: "Add a book called 'Brave New World' by Aldous Huxley to the book API.",
    negativeCapability: true,
    goal: {
      type: 'response_indicates_limitation',
      keywords: [
        'required',
        'Open Library',
        'book field',
        'missing',
        'mandatory',
        'cannot create',
        'need',
        'OL',
        'identifier',
        'ID',
      ],
    },
  },
  {
    id: 'T2.6',
    tier: 2,
    prompt: "Using the book API, change the author of 'Hyperion' to 'Daniel Simmons'.",
    negativeCapability: true,
    goal: {
      type: 'response_indicates_limitation',
      keywords: [
        'read-only',
        'read only',
        'not writable',
        'cannot change',
        "can't change",
        'cannot update',
        "can't update",
        'cannot modify',
        "can't modify",
        'not allowed',
        'immutable',
        'server-hydrated',
        'not a writable',
      ],
    },
  },

  // === Tier 3 — Multi-step reasoning ===
  {
    id: 'T3.1',
    tier: 3,
    prompt:
      "Using the book API, find all books by Vera Strange and update their condition to 'good'.",
    negativeCapability: false,
    goal: {
      type: 'api_state',
      method: 'GET',
      url: '/books?author=Vera+Strange',
      expect: { all_members_condition_contains: 'Good' },
    },
  },
  {
    id: 'T3.2',
    tier: 3,
    prompt:
      "Check if the book API has any books by Philip Francis Nowlan. If not, add one with Open Library ID OL37044569M.",
    negativeCapability: false,
    goal: {
      // "The Airlords of Han" already exists — agent should NOT add a duplicate
      type: 'response_contains',
      values: ['Nowlan'],
    },
  },
  {
    id: 'T3.3',
    tier: 3,
    prompt:
      "Using the book API, find 'Hyperion', read its reviews, and tell me the highest-rated one.",
    negativeCapability: false,
    goal: {
      type: 'response_contains',
      values: ['5'],
    },
  },
  {
    id: 'T3.4',
    tier: 3,
    prompt: "Using the book API, bookmark 'Hyperion'.",
    negativeCapability: false,
    goal: {
      type: 'response_match',
      check: 'manual',
    },
  },

  // === Tier 4 — Cross-collection + discovery stress ===
  {
    id: 'T4.1',
    tier: 4,
    prompt: 'What collections are available in the book API? List them all.',
    negativeCapability: false,
    goal: {
      type: 'response_contains',
      values: ['book', 'review', 'bookmark'],
    },
  },
  {
    id: 'T4.2',
    tier: 4,
    prompt:
      "Using the book API, add a book (Open Library ID OL35227850M, condition 'new') and then write a review for it: 'Fascinating read', rating 4.",
    negativeCapability: false,
    goal: {
      type: 'api_state',
      method: 'GET',
      url: '/books?book=/books/OL35227850M',
      expect: { 'hydra:totalItems_gte': 1 },
    },
  },
  {
    id: 'T4.3',
    tier: 4,
    prompt:
      "Using the book API, search for books by 'Gordon R. Dickson', then look at the reviews for each one. Summarize what you found.",
    negativeCapability: false,
    goal: {
      type: 'response_contains',
      values: ['Gordon R. Dickson'],
    },
  },
  {
    id: 'T4.4',
    tier: 4,
    prompt: "Can you create a new user named 'Alice' in the book API?",
    negativeCapability: true,
    goal: {
      type: 'response_indicates_limitation',
      keywords: [
        'read-only',
        'read only',
        'cannot create',
        "can't create",
        'no POST',
        'not supported',
        'not allowed',
        'only GET',
        'no create',
        'GET only',
      ],
    },
  },
];
