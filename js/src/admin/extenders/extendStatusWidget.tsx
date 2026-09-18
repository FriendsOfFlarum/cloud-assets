import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import StatusWidget from 'flarum/admin/components/StatusWidget';
import StatusWidgetItem from 'flarum/admin/components/StatusWidgetItem';
import Tooltip from 'flarum/common/components/Tooltip';

import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

interface CloudAssetsPayload {
  provider: string;
  bucket: string | null;
  host: string | null;
}

export default function extendStatusWidget() {
  extend(StatusWidget.prototype, 'items', function (items: ItemList<Mithril.Children>) {
    const cloud = app.data.cloudAssets as CloudAssetsPayload | undefined;

    // Absent when no bucket is configured, in which case files are on the
    // filesystem and there is nothing to report.
    if (!cloud) return;

    items.add(
      'cloud-assets',
      <StatusWidgetItem
        icon="fas fa-cloud"
        label={app.translator.trans('fof-cloud-assets.admin.status.assets')}
        value={
          // The host is what asset URLs actually resolve to, which is the thing
          // worth seeing at a glance — and the first thing to check when assets
          // 404 or serve something stale. Which provider and bucket sit behind
          // it is detail, so it goes in the tooltip: bucket names in particular
          // run long, and this widget lays its items out in a row where one
          // long value pushes the rest around.
          <Tooltip text={[cloud.provider, cloud.bucket].filter(Boolean).join(' · ')} appendTo="body">
            <span>{cloud.host ?? cloud.provider}</span>
          </Tooltip>
        }
      />,
      50
    );
  });
}
