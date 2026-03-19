const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
  ...defaultConfig,
  entry: {
    admin: path.resolve(__dirname, 'client/admin.js'),
  },
  output: {
    ...defaultConfig.output,
    path: path.resolve(__dirname, 'build'),
  },
};
