module.exports = {
    root: true,
    env: {
        browser: true,
        es2021: true,
        node: true,
    },
    extends: [
        'eslint:recommended',
        'plugin:vue/vue3-recommended',
    ],
    parserOptions: {
        ecmaVersion: 'latest',
        sourceType: 'module',
        // ENG-15: <script lang="ts"> in components; plain <script> stays on the default JS parser
        parser: { ts: '@typescript-eslint/parser', js: 'espree', '<template>': 'espree' },
    },
    overrides: [
        {
            files: ['*.ts'],
            parser: '@typescript-eslint/parser',
            rules: { 'no-unused-vars': 'off', 'no-undef': 'off' },   // TypeScript checks these itself (vue-tsc)
        },
    ],
    rules: {
        // Pragmatic starting point for a codebase with no prior lint
        // config at all (matches the phpstan.neon comment's reasoning):
        // these catch real bugs without demanding a repo-wide reformat
        // as a side effect of turning linting on for the first time.
        'no-unused-vars': 'warn',
        'vue/multi-word-component-names': 'off',
        'vue/no-v-html': 'off',
    },
};
