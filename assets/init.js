$( function () {
	var taskTypeTemplateMapping = {},
		lang = $( 'html' ).attr( 'lang' ),
		topics = [],
		resetButton = new OO.ui.ButtonWidget( {
			label: 'Reset',
			flags: [
				'primary',
				'destructive'
			]
		} ),
		searchButton = new OO.ui.ButtonWidget( {
			label: 'Search',
			disabled: true,
			flags: [
				'primary',
				'progressive'
			]
		} ),
		langSelectWidget = new OO.ui.ButtonSelectWidget( {
			items: [
				new OO.ui.ButtonOptionWidget( {
					data: 'cs',
					label: 'cs'
				} ),
				new OO.ui.ButtonOptionWidget( {
					data: 'ar',
					label: 'ar'
				} ),
				new OO.ui.ButtonOptionWidget( {
					data: 'ko',
					label: 'ko'
				} ),
				new OO.ui.ButtonOptionWidget( {
					data: 'vi',
					label: 'vi'
				} )
			]
		} ),
		resultCount = 0,
		info = new OO.ui.MessageWidget( {
			type: 'notice',
			label: 'Task detail',
			classes: [ 'task-info' ]
		} ).toggle( false ),
		$wrapper = $( '.wrapper' ),
		$resultCountHtml = $( '<p>' ).addClass( 'result-count' ),
		$queryCountHtml = $( '<p>' ).addClass( 'query-count' ),
		taskTypeWidget = new OO.ui.CheckboxMultiselectWidget( {
			classes: [ 'task-type' ],
			items: []

		} ),
		hasTemplate = [],
		list = new OO.ui.SelectWidget( {
			classes: [ 'newcomer-tasks' ]
		} ),
		TaskOptionWidget = function ( config ) {
			config = config || {};
			TaskOptionWidget.parent.call( this, config );
			this.template = config.template;
			this.category = config.category;
		},
		topicWidget,
		controls,
		TopicSelectionWidget = function ( config ) {
			config = config || {};
			TopicSelectionWidget.parent.call( this, config );
		};

	OO.inheritClass( TaskOptionWidget, OO.ui.OptionWidget );
	OO.inheritClass( TopicSelectionWidget, OO.ui.MenuTagMultiselectWidget );

	TaskOptionWidget.prototype.getTemplate = function () {
		return this.template;
	};

	TaskOptionWidget.prototype.getMetadata = function () {
		return JSON.stringify( this.data, null, 2 );
	};

	topicWidget = new TopicSelectionWidget( {
		allowArbitrary: false,
		options: [],
		disabled: false
	} );

	controls = new OO.ui.FieldsetLayout( {
		label: null,
		items: [
			new OO.ui.FieldLayout(
				new OO.ui.Widget( {
					content: [
						new OO.ui.HorizontalLayout( {
							items: [
								langSelectWidget, topicWidget, taskTypeWidget
							]
						} )
					]
				} )
			),
			new OO.ui.FieldLayout(
				new OO.ui.Widget( {
					content: [
						new OO.ui.HorizontalLayout( {
							items: [ searchButton, resetButton ]
						} )
					]
				} ) )
		]
	} );

	function getTopics() {
		topicWidget.clearItems();
		topicWidget.getMenu().clearItems();
		$.get( '/assets/topics.json', function ( response ) {
			response.forEach( function ( topic ) {
				topicWidget.addOptions( [
					topicWidget.createMenuOptionWidget( topic, topic )
				] );
			} );
		} );

	}

	function appendResultsToTaskOptions( result, template ) {
		if ( list.findItemFromData( result ) === null ) {
			resultCount += 1;
			$wrapper.find( '.result-count' )
				.text( resultCount + ' results found' );
			list.addItems( [
				new TaskOptionWidget( {
					data: result,
					template: template,
					label: result.page_title
				} )
			] );
		}
	}

	function doSearch() {
		info.toggle( false );
		list.clearItems();
		list.toggle( true );
		resultCount = 0;
		$wrapper.find( '.result-count' ).toggle( true );
		hasTemplate = [];
		topics = [];

		topicWidget.getItems().forEach( function ( item ) {
			topics.push( item.data );
		} );
		taskTypeWidget.getItems().forEach( function ( item ) {
			if ( item.selected ) {
				hasTemplate.push( item.data );
			}
		} );
		if ( !hasTemplate.length ) {
			return;
		}
		hasTemplate.forEach( function ( templateGroup ) {
			$.get( '/assets/tasks.json', function ( response ) {
				response.forEach( function ( task ) {
					if ( task.lang !== lang ) {
						return;
					}
					if ( topics.length ) {
						if ( topics.includes( task.topic ) &&
							templateGroup.includes( task.template ) ) {
							appendResultsToTaskOptions( task, task.template );
						}
					} else {
						// Template only search.
						if ( templateGroup.includes( task.template ) ) {
							appendResultsToTaskOptions( task, task.template );
						}
					}
				} );
			} );
		} );
	}

	list.on( 'choose', function ( item ) {
		info.toggle( true );
		info.setLabel(
			new OO.ui.HtmlSnippet(
				'<strong><a href="https://' + lang + '.wikipedia.org/wiki/' + item.data.page_title + '">' + item.data.page_title + '</a></strong>' +
				'<br>' +
				item.data.enwiki_title +
			'<br>' +
			'<p><strong>Template:</strong> ' + item.getTemplate() +
				'<p><code>' + item.getMetadata() + '</code></p>'
			)
		);
	} );

	searchButton.on( 'click', function () {
		doSearch();
	} );

	function doReset() {
		hasTemplate = [];
		resultCount = 0;
		taskTypeTemplateMapping = [];
		taskTypeWidget.clearItems();
		topicWidget.clearItems();
		topicWidget.getMenu().clearItems();
		topicWidget.setDisabled( true );
		searchButton.setDisabled( true );
		list.clearItems();
		info.toggle( false );
		$wrapper.find( '.result-count' )
			.toggle( false )
			.text( '' );
		$wrapper.find( '.query-count' )
			.toggle( false )
			.text( '' );
	}

	resetButton.on( 'click', function () {
		doReset();
	} );

	function getTemplatesForLang( lang ) {
		taskTypeWidget.clearItems();
		$.get( 'https://www.mediawiki.org/w/api.php', {
			titles: 'Growth/Personalized_first_day/Newcomer_tasks/Prototype/templates/' + lang + '.json',
			action: 'query',
			prop: 'revisions',
			rvprop: 'content',
			format: 'json',
			formatversion: 2,
			rvslots: '*',
			origin: '*'
		}, function ( response ) {
			var templates = JSON.parse(
					response.query.pages[ 0 ].revisions[ 0 ].slots.main.content
				),
				key;

			for ( key in templates ) {
				taskTypeWidget.addItems( [ new OO.ui.CheckboxMultioptionWidget( {
					data: templates[ key ].templates,
					label: templates[ key ].label
				} ) ] );
				taskTypeTemplateMapping[ key ] = templates[ key ];
			}
		} );
	}

	taskTypeWidget.on( 'select', function () {
		searchButton.setDisabled( true );
		taskTypeWidget.getItems().forEach( function ( item ) {
			if ( item.isSelected() ) {
				searchButton.setDisabled( false );
			}
		} );
	} );

	langSelectWidget.on( 'select', function ( item ) {
		if ( !item ) {
			return;
		}
		doReset();
		$( 'html' ).attr( 'lang', item.data );
		lang = item.data;
		hasTemplate = [];
		getTopics();
		topicWidget.setDisabled( false );
		getTemplatesForLang( lang );
	} );

	$wrapper.append(
		controls.$element,
		info.$element,
		$resultCountHtml,
		$queryCountHtml,
		list.$element
	);
} );
