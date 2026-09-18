import app from 'flarum/admin/app';
import extendStatusWidget from './extenders/extendStatusWidget';

app.initializers.add('fof-cloud-assets', () => {
  extendStatusWidget();
});
