import { h, Component } 				from 'preact/preact';

import NavLink 							from 'com/nav-link/link';
import NavSpinner 						from 'com/nav-spinner/spinner';
import SVGIcon							from 'com/svg-icon/icon';
import UIIcon							from 'com/ui/icon/icon';

import Common							from 'com/content-common/common';
import CommonBody						from 'com/content-common/common-body';

import $Node							from '../../shrub/js/node/node';

const GROUP_ICONS = {
	'tag': 'tag',
	'tag/platform': 'tag',
	'tag/genre': 'lightbulb',
	'tag/input': 'gamepad',
	'group': 'folder-open',
	'group/tags': 'tags',
	'group/events': 'trophys',
	'event': 'trophy',
};

const GROUP_SUFFIX = {
	'group': '/',
	'group/tags': '/',
	'group/events': '/',
};

export default class ContentGroup extends Component {
	constructor( props ) {
		super(props);

		this.state = {
			'items': null,
			'nodes': null
		};
	}

	getIconName(n) {
		let name = this.makePath(n);
		if ( GROUP_ICONS[name] ) {
			return GROUP_ICONS[name];
		}
		else if ( GROUP_ICONS[n.type] ) {
			return GROUP_ICONS[n.type];
		}

		return "file-empty";
	}

	makePath(n) {
		let ret = "";

		if ( n.type ) {
			ret += n.type;
		}
		if ( n.subtype ) {
			ret += '/' + n.subtype;
		}
		if ( n.subsubtype ) {
			ret += '/' + n.subsubtype;
		}
		return ret;
	}

	componentDidMount() {
		let props = this.props;
		let node = props.node;

		$Node.GetFeed(node.id, 'parent', ['group', 'event', 'page', 'tag'])
			.then(r => {
				if ( r && r.feed ) {
					this.setState({'items': r.feed});

					return $Node.GetKeyed(r.feed);
				}
			})
			.then( r => {
				if ( r && r.node ) {
					this.setState({'nodes': r.node});
				}
			});
	}


	render( {node, user}, {items, nodes} ) {
		let ShowBody = [];
		if ( items && items.length && nodes ) {
			ShowBody.push(<div><NavLink href={node.path+'/..'}><span><UIIcon src="previous" /> </span>../</NavLink></div>);

			for (let idx = 0; idx < items.length; idx++) {
				let n = nodes[items[idx].id];

				let prefix = <span>[{this.makePath(n)}]</span>;
				if ( GROUP_ICONS[this.makePath(n)] ) {
					prefix = <span><UIIcon src={this.getIconName(n)} /> </span>;
				}

				let suffix = '';
				if ( GROUP_SUFFIX[this.makePath(n)] ) {
					suffix = GROUP_SUFFIX[this.makePath(n)];
				}

				ShowBody.push(<div><NavLink href={n.path}>{prefix}{n.name}{suffix}</NavLink></div>);
			}
		}
		else if ( items && items.length == 0 ) {
			ShowBody.push(<div><NavLink href={node.path+'/..'}><span><UIIcon src="previous" /> </span>../</NavLink></div>);
		}
		else {
			ShowBody.push(<NavSpinner />);
		}

		{
			let suffix = '';
			if ( GROUP_SUFFIX[this.makePath(node)] ) {
				suffix = GROUP_SUFFIX[this.makePath(node)];
			}

			return (
				<Common node={node} user={user} header={node.name.toUpperCase()+suffix} headerIcon={this.getIconName(node)}>
					<CommonBody>
						<br /><br /><br />
						{ShowBody}
					</CommonBody>
				</Common>
			);
		}
	}
}
