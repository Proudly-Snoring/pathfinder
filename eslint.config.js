import globals from 'globals';

export default [
    {
        ignores: ['js/lib/**']
    },
    {
        files: ['js/app/**/*.js'],
        languageOptions: {
            ecmaVersion: 2018,
            sourceType: 'script',
            globals: {
                ...globals.browser,
                ...globals.jquery,
                ...globals.node,
                // AMD + vendored libs (formerly .jshintrc "predef")
                requirejs: 'readonly',
                define: 'readonly',
                jsPlumb: 'readonly',
                Magnetizer: 'readonly',
                Morris: 'readonly',
                TweenLite: 'readonly',
                Circ: 'readonly'
            }
        },
        rules: {
            eqeqeq: 'warn',
            quotes: ['warn', 'single'],
            'no-empty': 'warn',
            'no-undef': 'warn',
            'new-cap': ['warn', { properties: false }]
        }
    }
];
