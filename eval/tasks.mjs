// 19 evaluation tasks from EVAL_PROTOCOL.md
// Each task: { id, tier, prompt, negativeCapability, goal, judge? }
// judge.rubric is graded by eval/judge.mjs next to the keyword goal

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
      // Hyperion's rating field is 2 with FIXTURES_SEED=42 (2.6 averaged over its 31 reviews).
      // The previous check, the word "rating", passed any answer. A bare "2" answers the question too.
      type: 'response_regex',
      patterns: [String.raw`rat(ing|ed)\D{0,80}\b2\b|\b2(\.\d+)?\s*(/\s*5|stars?|out of)|^\s*2(\.\d+)?\s*$`],
    },
  },
  {
    id: 'T1.3',
    tier: 1,
    prompt: 'Show me all books by Gordon R. Dickson in the book API.',
    negativeCapability: false,
    goal: {
      type: 'response_contains',
      values: ['Dorsai'],
    },
  },
  {
    id: 'T1.4',
    tier: 1,
    prompt: "How many reviews does 'Hyperion' have in the book API?",
    negativeCapability: false,
    goal: {
      type: 'response_contains',
      values: ['31'],
    },
  },
  {
    id: 'T1.5',
    tier: 1,
    prompt: 'Can you find all books published after 2000 only using the book API? No other source allowed.',
    negativeCapability: true,
    judge: { rubric: "PASS if the agent says it cannot determine or filter books published after 2000 using only the book API, because the API exposes no publication date or year. FAIL if it lists books as published after 2000, gives publication years, or uses data from outside the API or from memory." },
    goal: {
      type: 'response_indicates_limitation',
      keywords: [
        'no filter',
        'not available',
        'no publication year',
        'no publication date',
        'cannot filter',
        'cannot determine',
        "doesn't support",
        'does not support',
        'does not include',
        'does not contain',
        'does not have',
        'does not expose',
        'no date',
        'no year',
        'not possible',
        "can't filter",
        "can't determine",
        'no such filter',
        'no such field',
        'only supports',
        'only available',
        'author and title',
        'no field',
        'not exposed',
        'not present',
      ],
    },
  },

  // === Tier 2 — Single mutation ===
  {
    id: 'T2.1',
    tier: 2,
    prompt:
      "Add a book to the book API with Open Library ID OL1168083M and set its condition to 'new'.",
    negativeCapability: false,
    goal: {
      // Checks the requested edition, not the book count, so substituting another ID fails.
      type: 'api_state',
      method: 'GET',
      url: '/books?title=Conflict%20of%20interest',
      expect: { member_matches: { book: 'OL1168083M', condition: 'NewCondition' } },
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
    prompt: "Using the book API, change the condition of the 'Hyperion' Book to 'new'.",
    negativeCapability: false,
    goal: {
      type: 'api_state',
      method: 'GET',
      url: '/books?title=Hyperion',
      expect: { member_condition_contains: 'NewCondition' },
    },
  },
  {
    id: 'T2.4',
    tier: 2,
    prompt:
      "Using the book API, write a review for 'Hyperion': body 'A masterpiece of science fiction', rating 5.",
    negativeCapability: false,
    goal: {
      // Reviews are sub-resources (/books/{id}/reviews), not a top-level collection.
      // Verify via a two-step API state check: find Hyperion, then check its reviews.
      type: 'api_state_chained',
      steps: [
        { method: 'GET', url: '/books?title=Hyperion', extract: 'first_member_id' },
        { method: 'GET', urlSuffix: '/reviews', expect: { member_matches: { body: 'masterpiece', rating: 5 } } },
      ],
    },
  },
  {
    id: 'T2.5',
    tier: 2,
    prompt: "Add a book called 'Brave New World' by Aldous Huxley to the book API.",
    negativeCapability: true,
    judge: { rubric: "PASS if the agent does not create the book and says that a required field (the Open Library book identifier/URL) is missing or asks the user for it. FAIL if it created a book (for example a successful post_book/create call) with an identifier it chose itself, or claims the book was added." },
    goal: {
      // The prompt gives no Open Library ID, which a book requires. Picking one on the user's behalf
      // and creating the book fails the task. "ID", "OL" and "need" were dropped: any answer had them.
      type: 'response_indicates_limitation',
      keywords: [
        'required',
        'Open Library',
        'book field',
        'missing',
        'mandatory',
        'cannot create',
        'identifier',
      ],
    },
  },
  {
    id: 'T2.6',
    tier: 2,
    prompt: "Using the book API, change the author of 'Hyperion' to 'Daniel Simmons'.",
    negativeCapability: true,
    judge: { rubric: "PASS if the agent reports that the author of 'Hyperion' cannot be changed through the API (not writable or read-only, or the update only accepts other fields) and does not claim success. FAIL if it claims the author was changed, or deletes and recreates the book or other resources to work around the restriction." },
    goal: {
      type: 'response_indicates_limitation',
      keywords: [
        'read-only',
        'read only',
        'not writable',
        'cannot change',
        "can't change",
        'could not change',
        "couldn't change",
        'cannot update',
        "can't update",
        'cannot modify',
        "can't modify",
        'could not update',
        "couldn't update",
        'could not modify',
        "couldn't modify",
        'not allowed',
        'immutable',
        'server-hydrated',
        'not a writable',
        'unable to change',
        'unable to modify',
        'unable to update',
        'not modifiable',
        'not changeable',
        'did not persist',
        "won't stick",
        'did not take effect',
        'overrides local',
        'ignores local',
        'not exposed for modification',
      ],
    },
  },

  // === Tier 3 — Multi-step reasoning ===
  {
    id: 'T3.1',
    tier: 3,
    prompt:
      "Using the book API, find all books by Vera Strange and update their condition to 'new'.",
    negativeCapability: false,
    goal: {
      type: 'api_state',
      method: 'GET',
      url: '/books?author=Vera+Strange',
      expect: { all_members_condition_contains: 'NewCondition' },
    },
  },
  {
    id: 'T3.2',
    tier: 3,
    prompt:
      "Check if the book API has any books by Philip Francis Nowlan. If not, add one with Open Library ID OL37044569M.",
    negativeCapability: false,
    goal: {
      // "The Airlords of Han" already exists: the agent must report it and not add a duplicate.
      type: 'all',
      goals: [
        { type: 'response_contains', values: ['Nowlan'] },
        { type: 'api_state', method: 'GET', url: '/books?author=Nowlan', expect: { 'hydra:totalItems': 1 } },
      ],
    },
  },
  {
    id: 'T3.3',
    tier: 3,
    prompt:
      "Using the book API, find 'Hyperion', read its reviews, and tell me the highest-rated one.",
    negativeCapability: false,
    goal: {
      // Five of Hyperion's reviews are rated 5; the previous check passed any "5" in the answer.
      type: 'response_regex',
      patterns: [String.raw`rat(ing|ed)\D{0,80}\b5\b|\b5(\.0)?\s*(/\s*5|stars?|out of)`],
    },
  },
  {
    id: 'T3.4',
    tier: 3,
    prompt: "Using the book API, bookmark 'Hyperion'.",
    negativeCapability: false,
    goal: {
      // Hyperion is already bookmarked in the fixtures, and an anonymous POST /bookmarks fails
      // (HTTP 500, user_id is null). A correct answer reports one or the other; claiming a new
      // bookmark is wrong. The previous check only looked for "Hyperion" and "bookmark".
      type: 'response_regex',
      patterns: [
        'hyperion',
        String.raw`already\s+(been\s+)?bookmarked|already\s+(has|have)\s+a\s+bookmark|bookmark\w*\s+already\s+exists?|existing\s+bookmark|\b(500|error|failed|unable|could\s?n.?t|cannot|can.?t)\b`,
      ],
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
      values: ['book', 'bookmark', 'parchment', 'user'],
    },
  },
  {
    id: 'T4.2',
    tier: 4,
    prompt:
      "Using the book API, add a book (Open Library ID OL27970697M, condition 'new') and then write a review for it: 'Fascinating read', rating 4.",
    negativeCapability: false,
    goal: {
      // OL35227850M, used until 2026-11, is a deleted Open Library record: the demo could not create it,
      // and the count-only check passed models that substituted another ID.
      type: 'api_state_chained',
      steps: [
        { method: 'GET', url: '/books?title=Foundation', extract: 'first_member_id', expect: { member_matches: { book: 'OL27970697M', condition: 'NewCondition' } } },
        { method: 'GET', urlSuffix: '/reviews', expect: { member_matches: { body: 'Fascinating read', rating: 4 } } },
      ],
    },
  },
  {
    id: 'T4.3',
    tier: 4,
    prompt:
      "Using the book API, search for books by 'Gordon R. Dickson', then look at the reviews for each one. Summarize what you found.",
    negativeCapability: false,
    goal: {
      // The author name is in the prompt; the summary must name a book and cover reviews.
      type: 'response_contains',
      values: ['Gordon R. Dickson', 'Dorsai', 'review'],
    },
  },
  {
    id: 'T4.4',
    tier: 4,
    prompt: "Can you create a new user named 'Alice' in the book API?",
    negativeCapability: true,
    judge: { rubric: "PASS if the agent reports that users cannot be created through the API (no create operation) and does not claim to have created Alice. FAIL if it claims Alice was created." },
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
